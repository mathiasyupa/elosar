<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Letterhead / prescription print settings for the active workspace.
 * Writing is gated by the settings.letterhead permission on the route.
 */
class UpdateLetterheadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prescription_settings' => ['required', 'array'],
            // How the letterhead is rendered: 'digital' (system draws it from the
            // text fields below) or 'image' (uploaded banner, see /settings/header-image).
            'prescription_settings.header_mode' => ['nullable', 'in:digital,image'],
            // How tall the uploaded banner may print, in mm (null = each document's default).
            'prescription_settings.header_image_height' => ['nullable', 'integer', 'min:10', 'max:80'],
            'prescription_settings.header' => ['nullable', 'string', 'max:255'],
            'prescription_settings.subheader' => ['nullable', 'string', 'max:255'],
            'prescription_settings.address' => ['nullable', 'string', 'max:255'],
            'prescription_settings.phone' => ['nullable', 'string', 'max:255'],
            'prescription_settings.email' => ['nullable', 'string', 'max:255'],
            'prescription_settings.footer' => ['nullable', 'string', 'max:1000'],
            // Issuing place ("lugar de emisión") printed before the date on the
            // receta, e.g. "RUMIÑAHUI 11-07-2026". It is the establishment's
            // canton/parish, not the patient's city.
            'prescription_settings.issue_city' => ['nullable', 'string', 'max:120'],
            // Days a prescription stays valid ("válida hasta"), MSP receta.
            'prescription_settings.validity_days' => ['nullable', 'integer', 'min:1', 'max:90'],

            // Designed sheet ("hoja diseñada"): off / background (printed behind
            // the content) / margins (the clinic prints on its physical paper,
            // so only the margins apply).
            'prescription_settings.sheet_mode' => ['nullable', 'in:off,background,margins'],
            'prescription_settings.sheet_keeps_header' => ['nullable', 'boolean'],
            // The paper each document goes out on (letterhead / sheet /
            // preprinted). Without it, sheet_mode applies to all of them.
            // Receta: two halves on a landscape A4 (default) or one portrait A4.
            'prescription_settings.prescription_layout' => ['nullable', 'in:landscape,portrait'],
            'prescription_settings.paper' => ['nullable', 'array:prescription,certificate,order'],
            'prescription_settings.paper.prescription' => ['nullable', 'in:letterhead,sheet,preprinted'],
            'prescription_settings.paper.certificate' => ['nullable', 'in:letterhead,sheet,preprinted'],
            'prescription_settings.paper.order' => ['nullable', 'in:letterhead,sheet,preprinted'],
            'prescription_settings.sheet_margins' => ['nullable', 'array'],
            'prescription_settings.sheet_margins.top' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'prescription_settings.sheet_margins.right' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'prescription_settings.sheet_margins.bottom' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'prescription_settings.sheet_margins.left' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
