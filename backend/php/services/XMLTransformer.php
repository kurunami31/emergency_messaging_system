<?php

namespace App\Services;

class XMLTransformer
{
    public function exportMessagesToXml(array $messages): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('emergency_messaging_export');
        $doc->appendChild($root);

        $generated = $doc->createElement('generated_at', date('c'));
        $root->appendChild($generated);
        $count = $doc->createElement('message_count', count($messages));
        $root->appendChild($count);

        $msgs = $doc->createElement('messages');
        $root->appendChild($msgs);

        foreach ($messages as $msg) {
            $el = $doc->createElement('message');
            foreach ($msg as $key => $value) {
                if ($value !== null) {
                    $child = $doc->createElement($key, htmlspecialchars((string)$value));
                    $el->appendChild($child);
                }
            }
            $msgs->appendChild($el);
        }

        return $doc->saveXML();
    }

    public function importMessagesFromXml(string $xmlContent): array
    {
        $doc = new \DOMDocument();
        $loaded = @$doc->loadXML($xmlContent);
        if (!$loaded) return ['imported_count' => 0, 'error' => 'Invalid XML'];

        $messages = [];
        $msgNodes = $doc->getElementsByTagName('message');
        foreach ($msgNodes as $node) {
            $msg = [];
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $msg[$child->nodeName] = $child->textContent;
                }
            }
            if (!empty($msg)) $messages[] = $msg;
        }

        $imported = 0;
        $messagingService = new MessagingService();
        foreach ($messages as $msg) {
            if (isset($msg['event_id'], $msg['content'])) {
                $eventId = (int)$msg['event_id'];
                $content = $msg['content'];
                $senderId = isset($msg['sender_id']) ? (int)$msg['sender_id'] : null;
                $priority = $msg['priority'] ?? 'normal';
                if ($senderId) {
                    $messagingService->sendMessage($eventId, $senderId, $content, $priority);
                } else {
                    $messagingService->sendSystemMessage($eventId, $content, $priority);
                }
                $imported++;
            }
        }

        return ['imported_count' => $imported];
    }
}
