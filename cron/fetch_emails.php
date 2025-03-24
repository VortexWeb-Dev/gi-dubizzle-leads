<?php
// IMAP Configuration
$IMAP_SERVER = "karak.tasjeel.ae";
$IMAP_PORT = 993;
$EMAIL_ACCOUNT = "dubizzleleads@giproperties.ae";
$PASSWORD = "ws6c_.Hph.wv_}_";

// PHP Script URL to send data
$PHP_SCRIPT_URL = "https://ec2-gicrm.ae/gi-dubizzle-leads/index.php";

// Allowed sender
$ALLOWED_SENDER = "no-reply@email.dubizzle.com";

// Connect to IMAP server
echo "Connecting to IMAP server...\n";
$inbox = imap_open("{" . $IMAP_SERVER . ":" . $IMAP_PORT . "/imap/ssl}INBOX", $EMAIL_ACCOUNT, $PASSWORD);

if (!$inbox) {
    die("IMAP connection failed: " . imap_last_error());
}

// Search for unread emails
$emails = imap_search($inbox, 'UNSEEN');

if (!$emails) {
    echo "No unread emails found.\n";
} else {
    echo "Found " . count($emails) . " unread email(s).\n";

    foreach ($emails as $email_number) {
        // Fetch the email header and body
        $header = imap_headerinfo($inbox, $email_number);
        $structure = imap_fetchstructure($inbox, $email_number);
        $body = get_email_body($inbox, $email_number, $structure);

        $sender = strtolower($header->from[0]->mailbox . "@" . $header->from[0]->host);
        $subject = isset($header->subject) ? imap_utf8($header->subject) : "(No Subject)";

        // Filter only emails from allowed sender
        if (strpos($sender, strtolower($ALLOWED_SENDER)) === false) {
            echo "Skipping email from: $sender\n";
            continue;
        }

        echo "Processing Email from: $sender\n";
        echo "Subject: $subject\n";
        echo "Body length: " . strlen($body) . " characters\n";

        // Prepare JSON payload
        $payload = json_encode([
            "sender" => $sender,
            "subject" => $subject,
            "body" => $body
        ]);

        echo "Sending data to PHP script...\n";

        // Send data via POST request
        $response = send_post_request($PHP_SCRIPT_URL, $payload);

        if ($response['status'] == 200) {
            echo "Email processed successfully: $subject\n";
            // Mark email as read
            imap_setflag_full($inbox, $email_number, "\\Seen");
        } else {
            echo "Failed to send email data: $subject\n";
        }

        sleep(1); // Avoid hitting limits
    }
}

// Close IMAP connection
imap_close($inbox);
echo "Process completed.\n";

// Function to extract plain text email content
function get_email_body($inbox, $email_number, $structure)
{
    $body = "";

    if (!isset($structure->parts)) {
        $body = imap_body($inbox, $email_number);
    } else {
        foreach ($structure->parts as $index => $part) {
            if ($part->subtype == "PLAIN") {
                $body = imap_fetchbody($inbox, $email_number, $index + 1);
                break;
            }
        }
    }

    // Decode content if needed
    if ($structure->encoding == 3) { // Base64
        $body = base64_decode($body);
    } elseif ($structure->encoding == 4) { // Quoted-printable
        $body = quoted_printable_decode($body);
    }

    // Clean the text
    $body = preg_replace('/<[^>]+>/', ' ', $body); // Remove HTML tags
    $body = preg_replace('/\s+/', ' ', $body); // Remove extra spaces
    return trim($body);
}

// Function to send POST request
function send_post_request($url, $payload)
{
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return ['status' => $http_status, 'response' => $response];
}
