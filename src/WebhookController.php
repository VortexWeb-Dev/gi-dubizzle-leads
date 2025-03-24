<?php
require_once __DIR__ . "/../crest/crest.php";
require_once __DIR__ . "/../utils.php";

define('CONFIG', require_once __DIR__ . '/../config.php');

class WebhookController
{
    private LoggerController $logger;
    private BitrixController $bitrix;

    public function __construct()
    {
        $this->logger = new LoggerController();
        $this->bitrix = new BitrixController();
    }

    // Handles incoming webhooks
    public function handleRequest(): void
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->sendResponse(405, [
                    'error' => 'Method Not Allowed. Only POST is accepted.'
                ]);
            }

            $handlerMethod = 'handleDubizzleLeads';

            $data = $this->parseRequestData();
            if ($data === null) {
                $this->sendResponse(400, [
                    'error' => 'Invalid JSON data'
                ]);
            }

            $this->$handlerMethod($data);
        } catch (Throwable $e) {
            $this->logger->logError('Error processing request', $e);
            $this->sendResponse(500, [
                'error' => 'Internal server error'
            ]);
        }
    }

    // Parses incoming JSON data
    private function parseRequestData(): ?array
    {
        $rawData = file_get_contents('php://input');
        $data = json_decode($rawData, true);
        return extract_data(json_encode($data));
    }

    // Sends response back to the webhook
    private function sendResponse(int $statusCode, array $data): void
    {
        header("Content-Type: application/json");
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }

    // Handles dubizzle webhook event
    public function handleDubizzleLeads(array $data): void
    {
        $this->logger->logWebhook('dubizzle-lead', $data);

        $type = in_array($data['type'], ['call', 'missed_call']) ? 'Call' : 'Lead';
        $reference = $data['body']['ref_no'] ?? '';
        $agentName = $data['body']['agent_name'] ?? '';

        $assignedById = !empty($reference)
            ? getResponsiblePerson($reference, 'reference')
            : (!empty($agentName) ? getResponsiblePerson($agentName, 'agent_name') : CONFIG['DEFAULT_RESPONSIBLE_PERSON_ID']);

        $title = "Dubizzle - $type - " . (!empty($reference) ? $reference : 'No reference');

        $clientName = $data['body']['client_name'] ?? $title;
        $clientPhone = $data['body']['client_phone'] ?? $data['body']['caller_number'];
        $clientEmail = $data['body']['client_email'] ?? '';

        $contactId = $this->bitrix->createContact([
            'NAME'        => $clientName,
            'PHONE'       => [['VALUE' => $clientPhone, 'VALUE_TYPE' => 'WORK']],
            'EMAIL'       => [['VALUE' => $clientEmail, 'VALUE_TYPE' => 'WORK']],
            'SOURCE_ID'   => $type === 'Call' ? CONFIG['DUBIZZLE_CALL'] : CONFIG['DUBIZZLE_EMAIL'],
            'ASSIGNED_BY_ID' => $assignedById
        ]);

        $fields = [
            'TITLE'             => $title,
            'CATEGORY_ID'       => CONFIG['SECONDARY_PIPELINE_ID'],
            'ASSIGNED_BY_ID'    => $assignedById,
            'SOURCE_ID'         => $type === 'Call' ? CONFIG['DUBIZZLE_CALL'] : CONFIG['DUBIZZLE_EMAIL'],
            'UF_CRM_1721198189214' => $clientName,
            'UF_CRM_1736406984' => $clientPhone,
            'UF_CRM_1721198325274' => $clientEmail,
            'UF_CRM_1739890146108' => $reference,
            'OPPORTUNITY'       => getPropertyPrice($reference) ?? '',
            'COMMENTS'          => formatComments($data),
            'CONTACT_ID'        => $contactId,
        ];

        $leadId = $this->bitrix->addLead($fields);

        $this->sendResponse(200, [
            'message' => 'Email data processed successfully and lead created with ID: ' . $leadId,
        ]);
    }
}
