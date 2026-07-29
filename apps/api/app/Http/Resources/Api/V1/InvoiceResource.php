<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform an invoice and its optionally loaded items into the billing API contract.
 */
class InvoiceResource extends JsonResource
{
    /**
     * Convert the invoice resource into an array for the current request.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'version' => $this->lock_version,
            'number' => $this->invoice_number,
            'patientId' => $this->patient_id,
            'patientDisplayName' => trim((string) $this->patient?->first_name.' '.(string) $this->patient?->last_name),
            'status' => $this->status->value,
            'currency' => $this->currency,
            'subtotalMinor' => $this->subtotal_minor,
            'taxMinor' => $this->tax_minor,
            'totalMinor' => $this->total_minor,
            'balanceMinor' => $this->balance_minor,
            'issuedOn' => $this->issued_on?->toDateString(),
            'dueOn' => $this->due_on?->toDateString(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->getKey(),
                'serviceCode' => $item->service_code,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unitPriceMinor' => $item->unit_price_minor,
                'taxMinor' => $item->tax_minor,
                'totalMinor' => $item->total_minor,
            ])),
        ];
    }
}
