<?php
declare(strict_types=1);

require_once __DIR__ . '/../m365/crm_m365_writer.php';

echo "=== CRM M365 Writer Test ===\n\n";
exit;

$job = [
    'upn'    => 'buss@hcmedv.de',
    'method' => 'POST',
    'url'    => '/users/buss@hcmedv.de/sendMail',
    'payload'=> [
        'message' => [
            'subject' => 'CRM Writer Test',
            'body' => [
                'contentType' => 'Text',
                'content'     => "Hallo,\n\nMail-Test über neuen CRM M365 Writer.\n\nOK.",
            ],
            'toRecipients' => [
                [
                    'emailAddress' => [
                        'address' => 'buss@hcmedv.de',
                    ],
                ],
            ],
        ],
        'saveToSentItems' => true,
    ],
];

$result = FN_M365_Write($job);

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
