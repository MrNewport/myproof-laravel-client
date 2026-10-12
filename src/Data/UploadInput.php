<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

final readonly class UploadInput
{
    public function __construct(public string $name, public string $filename, public int $sizeBytes, public string $mime, public bool $rightsAcknowledged, public ?string $assetId = null) {}

    public function toArray(): array
    {
        $data = ['name' => $this->name, 'filename' => $this->filename, 'size_bytes' => $this->sizeBytes, 'mime' => $this->mime, 'rights_acknowledged' => $this->rightsAcknowledged];
        if ($this->assetId !== null) {
            $data['asset_id'] = $this->assetId;
        }

        return $data;
    }
}
