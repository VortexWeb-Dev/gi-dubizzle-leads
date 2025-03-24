import imaplib
import email
import json
import requests
import time
import re

# IMAP Configuration
IMAP_SERVER = "karak.tasjeel.ae"
IMAP_PORT = 993
EMAIL_ACCOUNT = "dubizzleleads@giproperties.ae"
PASSWORD = "ws6c_.Hph.wv_}_"

# PHP Script URL
PHP_SCRIPT_URL = "https://ec2-gicrm.ae/gi-dubizzle-leads/index.php"

# Allowed sender
ALLOWED_SENDER = "no-reply@email.dubizzle.com"

def extract_plain_text(msg):
    """Extract plain text content from an email message and clean it for easier parsing."""
    body = ""
    
    if msg.is_multipart():
        plain_part = None
        for part in msg.walk():
            content_type = part.get_content_type()
            content_disposition = str(part.get("Content-Disposition"))
            
            if content_type == "text/plain" and "attachment" not in content_disposition:
                plain_part = part
                break
        
        if plain_part:
            body = plain_part.get_payload(decode=True).decode(errors="replace")
    else:
        body = msg.get_payload(decode=True).decode(errors="replace")
    
    # Clean the text
    body = re.sub(r'<[^>]+>', ' ', body)
    body = re.sub(r'\s+', ' ', body)
    body = body.strip()
    
    return body

try:
    # Connect to IMAP server
    print("Connecting to IMAP server...")
    mail = imaplib.IMAP4_SSL(IMAP_SERVER, IMAP_PORT)
    mail.login(EMAIL_ACCOUNT, PASSWORD)
    mail.select("inbox")
    
    # Search for unread emails
    print("Searching for unread emails...")
    status, messages = mail.search(None, "UNSEEN")
    
    if not messages[0]:
        print("No unread emails found.")
    else:
        email_count = len(messages[0].split())
        print(f"Found {email_count} unread email(s).")
        
        for msg_num in messages[0].split():
            status, msg_data = mail.fetch(msg_num, "(RFC822)")
            
            for response_part in msg_data:
                if isinstance(response_part, tuple):
                    msg = email.message_from_bytes(response_part[1])
                    sender = msg["from"].strip()
                    
                    # Filter only emails from no-reply@email.dubizzle.com
                    if ALLOWED_SENDER.lower() not in sender.lower():
                        print(f"Skipping email from {sender}")
                        continue
                    
                    subject = (msg["subject"] or "(No subject)").strip()
                    body = extract_plain_text(msg)
                    
                    print(f"Processing Email from: {sender}")
                    print(f"Subject: {subject}")
                    print(f"Body length: {len(body)} characters")
                    
                    # Prepare JSON payload
                    payload = {
                        "sender": sender,
                        "subject": subject,
                        "body": body
                    }
                    
                    print("Sending data to PHP script...")
                    headers = {"Content-Type": "application/json"}
                    
                    try:
                        response = requests.post(PHP_SCRIPT_URL, json=payload, headers=headers, timeout=10)
                        print(f"Response status code: {response.status_code}")
                        print(f"Response content: {response.text[:100]}...")
                        
                        if response.status_code == 200:
                            print(f"Email processed successfully: {subject}")
                            mail.store(msg_num, "+FLAGS", "\\Seen")
                        else:
                            print(f"Failed to send email data: {subject}")
                    except requests.exceptions.RequestException as e:
                        print(f"Error sending data to PHP script: {e}")
            
            time.sleep(1)
    
    # Logout
    mail.logout()
    print("Process completed.")
    
except imaplib.IMAP4.error as e:
    print(f"IMAP error occurred: {e}")
except Exception as e:
    print(f"An error occurred: {e}")
