<?php

require_once __DIR__ . "/crest/crest.php";

define('CONFIG', require_once __DIR__ . '/config.php');

// Formats the comments
function formatComments(array $data): string
{
    $output = [];

    $clientPhone = $data['body']['client_phone'] ?? $data['body']['caller_number'] ?? 'N/A';
    $output[] = "=== Client Details ===";
    $output[] = "Client Phone: $clientPhone";

    if (!empty($data['body']['client_name'])) {
        $output[] = "Client Name: " . $data['body']['client_name'];
    }
    if (!empty($data['body']['client_email'])) {
        $output[] = "Client Email: " . $data['body']['client_email'];
    }
    $output[] = "\n";

    if (!empty($data['body']['ref_no'])) {
        $propertyTitle = $data['body']['property_title'] ?? 'N/A';
        $propertyPrice = getPropertyPrice($data['body']['ref_no']) ?? 'N/A';

        $output[] = "=== Property Details ===";
        $output[] = "Reference Number: " . $data['body']['ref_no'];
        $output[] = "Property Title: $propertyTitle";
        $output[] = "Property Price: $propertyPrice";
        $output[] = "\n";
    }

    if (!empty($data['body']['date'])) {
        $callType = ($data['type'] === "missed_call") ? "Missed Call" : "Call";
        $callTime = $data['body']['time'] ?? 'N/A';

        $output[] = "=== Lead/Call Details ===";
        $output[] = "Call Date: " . $data['body']['date'];
        $output[] = "Call Time: $callTime";
        $output[] = "Call Type: $callType";
        $output[] = "";
    }

    return implode("\n", array_filter($output));
}

// Gets the user ID
function getUserId(array $filter): ?int
{
    $response = CRest::call('user.get', [
        'filter' => array_merge($filter, ['ACTIVE' => 'Y']),
    ]);

    if (!empty($response['error'])) {
        error_log('Error getting user: ' . $response['error_description']);
        return null;
    }

    if (empty($response['result'])) {
        return null;
    }

    if (empty($response['result'][0]['ID'])) {
        return null;
    }

    return (int)$response['result'][0]['ID'];
}

// Gets the responsible person ID
function getResponsiblePerson(string $searchValue, string $searchType): ?int
{
    if ($searchType === 'reference') {
        $response = CRest::call('crm.item.list', [
            'entityTypeId' => CONFIG['LISTINGS_ENTITY_TYPE_ID'],
            'filter' => ['ufCrm37ReferenceNumber' => $searchValue],
            'select' => ['ufCrm37ReferenceNumber', 'ufCrm37AgentEmail', 'ufCrm37ListingOwner', 'ufCrm37OwnerId'],
        ]);

        if (!empty($response['error'])) {
            error_log(
                'Error getting CRM item: ' . $response['error_description']
            );
            return CONFIG['DEFAULT_RESPONSIBLE_PERSON_ID'];
        }

        if (
            empty($response['result']['items']) ||
            !is_array($response['result']['items'])
        ) {
            error_log(
                'No listing found with reference number: ' . $searchValue
            );
            return CONFIG['DEFAULT_RESPONSIBLE_PERSON_ID'];
        }

        $listing = $response['result']['items'][0];

        $ownerId = $listing['ufCrm37OwnerId'] ?? null;
        if ($ownerId && is_numeric($ownerId)) {
            return (int)$ownerId;
        }

        $ownerName = $listing['ufCrm37ListingOwner'] ?? null;

        if ($ownerName) {
            $nameParts = explode(' ', trim($ownerName), 2);

            $firstName = $nameParts[0] ?? null;
            $lastName = $nameParts[1] ?? null;

            return getUserId([
                '%NAME' => $firstName,
                '%LAST_NAME' => $lastName,
                '!ID' => [3, 268]
            ]);
        }


        $agentEmail = $listing['ufCrm37AgentEmail'] ?? null;
        if ($agentEmail) {
            return getUserId([
                'EMAIL' => $agentEmail,
                '!ID' => 3,
                '!ID' => 268
            ]);
        } else {
            error_log(
                'No agent email found for reference number: ' . $searchValue
            );
            return CONFIG['DEFAULT_RESPONSIBLE_PERSON_ID'];
        }
    } else if ($searchType === 'agent_name') {
        $name_parts = explode(' ', $searchValue, 2);
        $firstName = $name_parts[0] ?? null;
        $lastName = $name_parts[1] ?? null;

        $userId = getUserId([
            '%NAME' => $firstName,
            '%LAST_NAME' => $lastName,
            '!ID' => [3, 268]
        ]);

        if (!$userId) {
            $userId = getUserId([
                '%NAME' => $searchValue,
                '!ID' => [3, 268]
            ]);
        }

        if (!$userId && $lastName) {
            $userId = getUserId([
                '%NAME' => $lastName,
                '!ID' => [3, 268]
            ]);
        }

        return $userId;
    }


    return CONFIG['DEFAULT_RESPONSIBLE_PERSON_ID'];
}

// Gets the property price
function getPropertyPrice($propertyReference)
{
    $response = CRest::call('crm.item.list', [
        'entityTypeId' => CONFIG['LISTINGS_ENTITY_TYPE_ID'],
        'filter' => ['ufCrm37ReferenceNumber' => $propertyReference],
        'select' => ['ufCrm37Price'],
    ]);

    return $response['result']['items'][0]['ufCrm37Price'] ?? null;
}

function extract_data($json_input)
{
    $email_data = json_decode($json_input, true);

    if (!$email_data || !is_array($email_data)) {
        return [
            'error' => 'Invalid JSON input'
        ];
    }

    $from_address = '';
    if (isset($email_data['sender'])) {
        if (preg_match('/<([^>]+)>/', $email_data['sender'], $matches)) {
            $from_address = $matches[1];
        } else {
            $from_address = $email_data['sender'];
        }
    }

    $subject = '';
    if (isset($email_data['subject'])) {
        $subject = iconv_mime_decode($email_data['subject'], 0, 'UTF-8');
    }

    $type = (strpos($subject, 'missed a call') !== false) ? 'missed_call' : ((strpos($subject, 'dubizzle - someone is interested') !== false) ? 'lead' : 'call');

    $result = [
        'from_address' => $from_address,
        'subject' => $subject,
        'type' => $type,
        'body' => []
    ];

    if (isset($email_data['body']) && !empty($email_data['body'])) {
        $body_text = $email_data['body'];

        if ($type === 'missed_call' || $type === 'call') {
            preg_match('/Hello\s+([^,]+),/i', $body_text, $agent_matches);
            $result['body']['agent_name'] = !empty($agent_matches[1]) ? trim($agent_matches[1]) : '';

            preg_match('/Date\s+(\d+\s+\w+\s+\d+)/i', $body_text, $date_matches);
            $result['body']['date'] = !empty($date_matches[1]) ? trim($date_matches[1]) : '';

            preg_match('/Time\s+(\d+:\d+:\d+\s+[AP]M)/i', $body_text, $time_matches);
            $result['body']['time'] = !empty($time_matches[1]) ? trim($time_matches[1]) : '';

            preg_match('/Caller\s+Number\s+(\+\d+)/i', $body_text, $caller_matches);
            $result['body']['caller_number'] = !empty($caller_matches[1]) ? trim($caller_matches[1]) : '';
        } elseif ($type === 'lead') {
            // Extract property title from subject
            if (preg_match('/interested\s+in\s+your\s+(.+?)(?:\s+giproperties|\s+$)/i', $subject, $property_title_matches)) {
                $result['body']['property_title'] = trim($property_title_matches[1]);
            }

            preg_match('/Name:\s+([^\r\n]+?)(?:\s+Telephone:|$)/i', $body_text, $client_name_matches);
            $result['body']['client_name'] = !empty($client_name_matches[1]) ? trim($client_name_matches[1]) : '';

            preg_match('/Telephone:\s+(\+[0-9]+)(?:\s+Email:|$)/i', $body_text, $client_phone_matches);
            $result['body']['client_phone'] = !empty($client_phone_matches[1]) ? trim($client_phone_matches[1]) : '';

            preg_match('/Email:\s+([^\s]+@[^\s]+)(?:\s+Message:|$)/i', $body_text, $client_email_matches);
            $result['body']['client_email'] = !empty($client_email_matches[1]) ? trim($client_email_matches[1]) : '';

            preg_match('/Ref\s+No:\s+(giproperties-\d+)/i', $body_text, $ref_no_matches);
            if (empty($ref_no_matches[1]) && preg_match('/property\s+reference\s+number\s+(giproperties-\d+)/i', $body_text, $alt_ref_matches)) {
                $result['body']['ref_no'] = trim($alt_ref_matches[1]);
            } else {
                $result['body']['ref_no'] = !empty($ref_no_matches[1]) ? trim($ref_no_matches[1]) : '';
            }

            if (preg_match('/Message:\s+(.*?)(?:Already\s+sold\s+it\?|$)/is', $body_text, $message_matches)) {
                $result['body']['message'] = trim($message_matches[1]);
            }
        }
    }

    return $result;
}
