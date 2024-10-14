<?php
// dmarc.php
//require 'vendor/autoload.php';

$config = require 'config.php';

// Connect to IMAP
$imapStream = imap_open("{" . $config['imap_host'] . ":993/imap/ssl}INBOX", $config['imap_user'], $config['imap_pass']);
if (!$imapStream) {
    die('Could not connect: ' . imap_last_error());
}

function unzipData($zipData)
{
    $zip = new ZipArchive();
    $tmpZipFile = tempnam(sys_get_temp_dir(), 'dmarc_zip');
    file_put_contents($tmpZipFile, $zipData);

    if ($zip->open($tmpZipFile) === TRUE) {
        // Extract the files to a temporary directory
        $zip->extractTo(sys_get_temp_dir());
        $zip->close();

        // Find XML or GZIP files inside the extracted files
        $extractedFiles = scandir(sys_get_temp_dir());
        foreach ($extractedFiles as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'xml' || pathinfo($file, PATHINFO_EXTENSION) === 'gz') {
                $fullPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $file;
                return file_get_contents($fullPath); // Return the contents of the XML or GZ file
            }
        }
    }
    return false;
}
// Function to create a folder if it doesn't exist
function createMailboxIfNotExists($imapStream, $folderName)
{
    global $config;
    // List all mailboxes
    $mailboxes = imap_list($imapStream, "{" . $config['imap_host'] . ":993/imap/ssl}", "*");
    // Check if the folder already exists
    if ($mailboxes) {
        foreach ($mailboxes as $mailbox) {
            if (strpos($mailbox, $folderName) !== false) {
                return; // Folder already exists, exit the function
            }
        }
    }

    // Folder does not exist, create it
    if (imap_createmailbox($imapStream, "{" . $config['imap_host'] . ":993/imap/ssl}" . $folderName)) {
        echo "Created folder: $folderName\n";
    } else {
        echo "Failed to create folder: $folderName\n" . imap_last_error() . "\n";
    }
}

// Create necessary folders
createMailboxIfNotExists($imapStream, $config['source_folder']);
createMailboxIfNotExists($imapStream, $config['processed_folder']);
createMailboxIfNotExists($imapStream, $config['failed_folder']);

imap_close($imapStream);

$imapStream = imap_open("{" . $config['imap_host'] . ":993/imap/ssl}" . $config['source_folder'], $config['imap_user'], $config['imap_pass']);

// SQLite setup
$db = new PDO('sqlite:' . $config['sqlite_db']);
$db->exec("CREATE TABLE IF NOT EXISTS dmarc_reports (
    id INTEGER PRIMARY KEY,
    report_org_name TEXT,
    domain TEXT,
    envelope_to TEXT,
    envelope_from TEXT,
    source_ip TEXT,
    count INTEGER,
    date TEXT
)");

// Fetch emails
$emails = imap_search($imapStream, 'ALL');
print "Processing emailed records:\n";
if ($emails) {
    foreach ($emails as $emailNumber) {
        $structure = imap_fetchstructure($imapStream, $emailNumber);
        $attachments = [];
        // Check if the email contains a ZIP file
        if (isset($structure->subtype) && $structure->subtype == 'ZIP') {
            // Fetch the body (Base64 encoded ZIP)
            $filename = '';

            if (isset($structure->dparameters)) {
                foreach ($structure->dparameters as $param) {
                    if (strtolower($param->attribute) === 'filename') {
                        $filename = $param->value;
                        break;
                    }
                }
            } elseif (isset($structure->parameters)) {
                foreach ($structure->parameters as $param) {
                    if (strtolower($param->attribute) === 'name') {
                        $filename = $param->value;
                        break;
                    }
                }
            }
            $attachmentBody = imap_fetchbody($imapStream, $emailNumber, 1);

            // Decode Base64
            if ($structure->encoding == 3) { // Base64 encoding
                $attachmentBody = base64_decode($attachmentBody);
            }

            $attachments[] = [
                'filename' => $filename,
                'body' => $attachmentBody
            ];
        }
        // Parse all parts to extract attachments
        else if (isset($structure->parts) && count($structure->parts)) {
            for ($i = 0; $i < count($structure->parts); $i++) {
                $part = $structure->parts[$i];

                // Check if this part is an attachment (disposition is usually 'attachment')
                if (isset($part->disposition) && strtolower($part->disposition) == 'attachment') {
                    // Fetch the body part (attachment content)
                    $attachmentBody = imap_fetchbody($imapStream, $emailNumber, $i + 1);

                    // Decode Base64 if necessary
                    if ($part->encoding == 3) { // 3 = BASE64 encoding
                        $attachmentBody = base64_decode($attachmentBody);
                    }

                    $attachments[] = [
                        'filename' => $part->dparameters[0]->value,
                        'body' => $attachmentBody
                    ];
                }
            }
        }

        $foundXml = false;

        // Process attachments
        foreach ($attachments as $attachment) {
            if (substr($attachment['body'], 0, 2) === "PK") { // ZIP magic number
                // Extract the zip file and retrieve the XML or gzipped XML inside it
                $xmlData = unzipData($attachment['body']);
            } else  if (substr($attachment['body'], 0, 2) === "\x1f\x8b") { // Gzip magic number
                $xmlData = gzdecode($attachment['body']);
                if ($xmlData === false) {
                    echo "Failed to decompress attachment in email ID $emailNumber\n";
                    continue;
                }
            } else {
                $xmlData = $attachment['body']; // If it's not gzipped, assume it's plain XML
            }

            // Check if the attachment contains valid DMARC XML data
            if (preg_match('/<policy_published>(.*?)<\/policy_published>/s', $xmlData, $matches)) {
                $xml = simplexml_load_string($xmlData);
                $foundXml = true;
                // Parse the XML and store it in SQLite
                $reportOrg = $xml->report_metadata->org_name ?? '';
                foreach ($xml->record as $record) {
                    $envelope_to = (string) $record->identifiers->envelope_to ?? ''; // adjust based on your XML structure
                    $envelope_from = (string) $record->identifiers->envelope_from ?? ''; // adjust based on your XML structure
                    $domain = (string) $record->identifiers->header_from; // adjust based on your XML structure
                    $sourceIp = (string) $record->row->source_ip;
                    $count = (int) $record->row->count;
                    $endDate = $xml->report_metadata->date_range->end ?? null;
                    if ($endDate) {
                        $endDate = (int) $endDate;
                    }
                    $date = date('Y-m-d', $endDate);

                    $stmt = $db->prepare("INSERT INTO dmarc_reports (domain, report_org_name, envelope_from, envelope_to, source_ip, count, date) VALUES (?,?,?, ?, ?, ?, ?)");
                    $stmt->execute([$domain, $reportOrg, $envelope_from, $envelope_to, $sourceIp, $count, $date]);
                }

		print "+";
                // Move email to processed folder
                imap_mail_move($imapStream, $emailNumber, $config['processed_folder']);
                break; // Stop processing after finding the correct attachment
            }
        }

        if (!$foundXml) {
	    // Move email to failed folder if no XML data is found
	    print '-';
            imap_mail_move($imapStream, $emailNumber, $config['failed_folder']);
        }
    }
    imap_expunge($imapStream);
}
print "\n";

// Close IMAP connection
imap_close($imapStream);

// Print summary for the last month
$lastMonth = date('Y-m-d', strtotime('-1 month'));
$summary = $db->query("SELECT domain, SUM(count) as total_count FROM dmarc_reports WHERE date >= '$lastMonth' GROUP BY domain")->fetchAll(PDO::FETCH_ASSOC);

echo "DMARC Summary for the Last Month:\n";
foreach ($summary as $row) {
    echo "Domain: {$row['domain']}, Total Count: {$row['total_count']}\n";
}
