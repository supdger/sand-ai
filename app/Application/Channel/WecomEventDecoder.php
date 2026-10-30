<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use DOMDocument;
use DOMElement;

/** Verifies and decrypts encrypted WeCom self-built application callbacks. */
final class WecomEventDecoder
{
    public function verifyUrl(array $query, string $token, string $encodingAesKey, string $corpId, int $now): string
    {
        $echo = $query['echostr'] ?? null;
        if (!is_string($echo) || $echo === '' || strlen($echo) > 4096) self::invalid();
        $this->signature($query, $token, $echo, $now);
        $plain = $this->decrypt($echo, $encodingAesKey, $corpId);
        if ($plain === '' || strlen($plain) > 512 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $plain) === 1) self::invalid();
        return $plain;
    }

    /** @return array{message_id:string,conversation_id:string,sender_id:string,query:string,receiver_id:string,conversation_close:bool} */
    public function message(string $raw, array $query, string $token, string $encodingAesKey, string $corpId, string $agentId, int $now): array
    {
        if ($raw === '' || strlen($raw) > 65536) self::invalid();
        $outer = self::xml($raw);
        $encrypted = $outer['Encrypt'] ?? null;
        if (!is_string($encrypted) || $encrypted === '') self::invalid();
        if (isset($outer['ToUserName']) && !hash_equals($corpId, $outer['ToUserName'])) self::invalid();
        $this->signature($query, $token, $encrypted, $now);
        $message = self::xml($this->decrypt($encrypted, $encodingAesKey, $corpId));
        if (($message['ToUserName'] ?? null) !== $corpId) self::invalid();
        if (($message['MsgType'] ?? null) !== 'text') {
            throw new ApiProblem('SAND_AI_CHANNEL_UNSUPPORTED_MESSAGE', 'WeCom message type is unsupported');
        }
        if (($message['AgentID'] ?? null) !== $agentId) self::invalid();
        $sender = $message['FromUserName'] ?? null;
        $messageId = $message['MsgId'] ?? null;
        $content = $message['Content'] ?? null;
        if (!is_string($sender) || $sender === '' || strlen($sender) > 128
            || !is_string($messageId) || preg_match('/^[0-9]{1,32}$/D', $messageId) !== 1
            || !is_string($content) || trim($content) === '' || mb_strlen($content, 'UTF-8') > 500) self::invalid();
        return ['message_id' => $messageId, 'conversation_id' => $sender,
            'sender_id' => $sender, 'query' => trim($content), 'receiver_id' => $sender,
            'conversation_close' => ChannelBindingPolicy::closesConversation($content)];
    }

    /** @param array<string,mixed> $query */
    private function signature(array $query, string $token, string $encrypted, int $now): void
    {
        $timestamp = $query['timestamp'] ?? null;
        $nonce = $query['nonce'] ?? null;
        $signature = $query['msg_signature'] ?? null;
        if ($token === '' || !is_string($timestamp) || preg_match('/^[0-9]{10}$/D', $timestamp) !== 1
            || abs((int) $timestamp - $now) > 300 || !is_string($nonce) || $nonce === '' || strlen($nonce) > 128
            || !is_string($signature) || preg_match('/^[a-f0-9]{40}$/D', $signature) !== 1) self::invalid();
        $parts = [$token, $timestamp, $nonce, $encrypted];
        sort($parts, SORT_STRING);
        if (!hash_equals(sha1(implode('', $parts)), $signature)) self::invalid();
    }

    private function decrypt(string $encrypted, string $encodingAesKey, string $corpId): string
    {
        if (preg_match('/^[A-Za-z0-9]{43}$/D', $encodingAesKey) !== 1 || $corpId === '' || strlen($corpId) > 128) self::invalid();
        $key = base64_decode($encodingAesKey . '=', true);
        $cipher = base64_decode($encrypted, true);
        if (!is_string($key) || strlen($key) !== 32 || !is_string($cipher)
            || strlen($cipher) < 32 || strlen($cipher) > 65536 || strlen($cipher) % 16 !== 0) self::invalid();
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 0, 16));
        if (!is_string($plain) || strlen($plain) < 21) self::invalid();
        $padding = ord($plain[strlen($plain) - 1]);
        if ($padding < 1 || $padding > 32 || !hash_equals(str_repeat(chr($padding), $padding), substr($plain, -$padding))) self::invalid();
        $plain = substr($plain, 0, -$padding);
        if (strlen($plain) < 20 + strlen($corpId)) self::invalid();
        $length = unpack('Nlength', substr($plain, 16, 4));
        $messageLength = $length['length'] ?? null;
        if (!is_int($messageLength) || $messageLength < 1 || $messageLength > 65536
            || strlen($plain) !== 20 + $messageLength + strlen($corpId)
            || !hash_equals($corpId, substr($plain, 20 + $messageLength))) self::invalid();
        return substr($plain, 20, $messageLength);
    }

    /** @return array<string,string> */
    private static function xml(string $raw): array
    {
        if ($raw === '' || strlen($raw) > 65536 || stripos($raw, '<!DOCTYPE') !== false || stripos($raw, '<!ENTITY') !== false) self::invalid();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($raw, LIBXML_NONET | LIBXML_COMPACT)) self::invalid();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (!$root instanceof DOMElement || $root->tagName !== 'xml') self::invalid();
        $fields = [];
        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                if (trim($child->textContent) !== '') self::invalid();
                continue;
            }
            if (isset($fields[$child->tagName]) || $child->attributes->length !== 0) self::invalid();
            foreach ($child->childNodes as $nested) if ($nested instanceof DOMElement) self::invalid();
            $fields[$child->tagName] = $child->textContent;
        }
        return $fields;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_CHANNEL_CALLBACK_INVALID', 'WeCom callback is invalid');
    }
}
