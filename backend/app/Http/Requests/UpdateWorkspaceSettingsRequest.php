<?php

namespace App\Http\Requests;

use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update of the active workspace's settings and fiscal (SRI) data
 * (Ajustes screen). The SRI certificate and its password are uploaded through
 * SettingsController::uploadCertificate, not here. Owner-only authorization is
 * enforced by the `permission:settings.manage` middleware.
 *
 * `sri_environment` accepts the same values the SRI engine reads ("test" /
 * "production"); accepting the ambiente digits ("1" / "2") here would persist a
 * value SriClaveAccesoBuilder and SriSoapClient do not recognize.
 */
class UpdateWorkspaceSettingsRequest extends FormRequest
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
            'name'                     => ['sometimes', 'string', 'max:255'],

            'ruc'                      => ['sometimes', 'nullable', 'string', 'size:13'],
            'razon_social'             => ['sometimes', 'nullable', 'string', 'max:300'],
            'nombre_comercial'         => ['sometimes', 'nullable', 'string', 'max:300'],
            'dir_matriz'               => ['sometimes', 'nullable', 'string', 'max:300'],
            'dir_establecimiento'      => ['sometimes', 'nullable', 'string', 'max:300'],
            'cod_establecimiento'      => ['sometimes', 'nullable', 'string', 'size:3'],
            'pto_emision'              => ['sometimes', 'nullable', 'string', 'size:3'],
            'contribuyente_especial'   => ['sometimes', 'nullable', 'string', 'max:20'],
            'obligado_contabilidad'    => ['sometimes', 'boolean'],
            'acess_establishment_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'senescyt_note'            => ['sometimes', 'nullable', 'string', 'max:255'],
            'sri_environment'          => ['sometimes', 'nullable', Rule::in(array_keys(Workspace::SRI_ENVIRONMENTS))],
            'sri_enabled'              => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Spanish, and specific enough to act on. The doctor UI now renders these
     * under the offending input, so they are product copy, not developer
     * diagnostics — "The ruc field must be 13 characters" told an emitter
     * nothing about where the missing digits come from.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ruc.size'                 => 'El RUC tiene 13 dígitos: tu cédula seguida de 001.',
            'cod_establecimiento.size' => 'El código de establecimiento tiene 3 dígitos (001 si solo tienes uno).',
            'pto_emision.size'         => 'El punto de emisión tiene 3 dígitos, normalmente 001.',
            'sri_environment.in'       => 'Elige un ambiente válido: pruebas o producción.',
            'razon_social.max'         => 'La razón social no puede pasar de 300 caracteres.',
            'nombre_comercial.max'     => 'El nombre comercial no puede pasar de 300 caracteres.',
            'dir_matriz.max'           => 'La dirección de matriz no puede pasar de 300 caracteres.',
            'dir_establecimiento.max'  => 'La dirección del establecimiento no puede pasar de 300 caracteres.',
            'name.max'                 => 'El nombre del espacio de trabajo no puede pasar de 255 caracteres.',
        ];
    }

    /**
     * Field names in Spanish, for the rules above that keep Laravel's wording.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name'                     => 'nombre',
            'ruc'                      => 'RUC',
            'razon_social'             => 'razón social',
            'nombre_comercial'         => 'nombre comercial',
            'dir_matriz'               => 'dirección de matriz',
            'dir_establecimiento'      => 'dirección del establecimiento',
            'cod_establecimiento'      => 'código de establecimiento',
            'pto_emision'              => 'punto de emisión',
            'contribuyente_especial'   => 'contribuyente especial',
            'obligado_contabilidad'    => 'obligado a llevar contabilidad',
            'acess_establishment_code' => 'código ACESS',
            'senescyt_note'            => 'registro SENESCYT',
            'sri_environment'          => 'ambiente SRI',
            'sri_enabled'              => 'facturación electrónica',
        ];
    }
}
