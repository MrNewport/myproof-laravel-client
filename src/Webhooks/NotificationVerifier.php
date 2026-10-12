<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Webhooks;

use MyProof\Evidence\CanonicalJson;
use MyProof\Evidence\EvidenceException;
use MyProof\Evidence\Schema;
use MyProof\LaravelClient\Exceptions\ApiException;

final readonly class NotificationVerifier
{
    public function __construct(#[\SensitiveParameter] private string $secret, private string $workspaceId, private string $mode)
    {
        if (! preg_match('/\Awhsec_[a-f0-9]{64}\z/', $secret)) {
            throw new \InvalidArgumentException('Invalid webhook verifier configuration.');
        }
        Schema::value($workspaceId, 'ulid');
        Schema::oneOf($mode, Schema::MODES);
    }

    /** Pass exact raw bytes, not decoded/re-encoded JSON. $now is for an application-controlled clock only. */
    public function verify(string $body, string $eventId, string $timestamp, string $signature, ?int $now = null): Notification
    {
        $now ??= time();
        if (strlen($body) > 8192 || ! preg_match('/\A[1-9][0-9]{0,9}\z/', $timestamp) || abs($now - (int) $timestamp) > 300
            || ! preg_match('/\Av1=[a-f0-9]{64}\z/', $signature)
            || ! hash_equals('v1='.hash_hmac('sha256', $timestamp.'.'.$body, $this->secret), $signature)) {
            throw new ApiException(401, 'invalid_notification');
        }
        try {
            $data = (new CanonicalJson)->requireCanonical($body, 8192);
            Schema::fields($data, ['schema', 'id', 'topic', 'workspace_id', 'delivery_id', 'mode', 'occurred_at', 'recorded_at', 'evidence', 'export']);
            Schema::oneOf($data->schema, ['myproof.notification.v1']);
            foreach (['id', 'workspace_id', 'delivery_id'] as $key) {
                Schema::value($data->{$key}, 'ulid');
            }
            Schema::oneOf($data->mode, [$this->mode]);
            Schema::oneOf($data->workspace_id, [$this->workspaceId]);
            Schema::oneOf($data->id, [$eventId]);
            Schema::value($data->occurred_at, 'timestamp');
            Schema::value($data->recorded_at, 'timestamp');
            $evidence = null;
            $export = null;
            if ($data->topic === 'export.ready') {
                Schema::oneOf($data->evidence, [null]);
                Schema::fields($data->export, ['id', 'status', 'privacy_mode']);
                Schema::value($data->export->id, 'ulid');
                Schema::oneOf($data->export->status, ['ready']);
                Schema::oneOf($data->export->privacy_mode, ['masked']);
                $export = new NotificationExport($data->export->id, $data->export->status, $data->export->privacy_mode);
            } else {
                Schema::oneOf($data->export, [null]);
                Schema::fields($data->evidence, ['event_id', 'type', 'source', 'sequence', 'sha256']);
                $event = $data->evidence;
                Schema::oneOf($event->event_id, [$data->id]);
                Schema::oneOf($event->type, array_keys(Schema::EVENTS));
                Schema::oneOf($event->source, Schema::EVENTS[$event->type][0]);
                Schema::value($event->sequence, 'uint');
                Schema::value($event->sha256, 'hash');
                if ($event->sequence < 1) {
                    throw new EvidenceException('Invalid sequence.');
                }
                $topic = match ($event->type) {
                    'order_snapshot_recorded' => 'delivery.created', 'help_requested' => 'help.requested', default => 'observation.added'
                };
                Schema::oneOf($data->topic, [$topic]);
                $evidence = new NotificationEvidence($event->event_id, $event->type, $event->source, $event->sequence, $event->sha256);
            }

            return new Notification($data->id, $data->workspace_id, $data->delivery_id, $data->mode, $data->topic, $data->occurred_at, $data->recorded_at, $evidence, $export);
        } catch (EvidenceException) {
            throw new ApiException(401, 'invalid_notification');
        }
    }

    public function __debugInfo(): array
    {
        return ['secret' => '[redacted]', 'workspaceId' => $this->workspaceId, 'mode' => $this->mode];
    }
}
