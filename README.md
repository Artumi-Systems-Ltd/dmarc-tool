# dmarc.php

This tool

- Connects to your email via IMAP
- Processes the contents of a folder to check for DMARC report
  attachments. Processed reports get moved to one folder, and
  unprocessed to another.
- Processes those attachments into an sqlite file
- Prints out summary information about what it's found.

For my purposes this is basically how many emails have been seen for
each of the sending domains over the period. If this doesn't go
too high then our domains are not being used for spam.

## Usage

Setup a config.php to access the emails, and set up dmarc to send
emails to that same address, and setup your email client to move dmarc
emails to that folder.

Then run dmarc.php every once in a while and look at the output.

## Todo

Record the spf passes and fails and dkim pases and fails per report,
and then report on the failures, and the ip addresses they are coming
from.


