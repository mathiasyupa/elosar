import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  deletePhoto,
  deleteSignature,
  fetchProfile,
  updateProfile,
  uploadPhoto,
  uploadSignature,
} from '@/api/profile';
import { useNavigate } from 'react-router-dom';
import { fetchMe, logoutEverywhere } from '@/api/auth';
import { createSpecialty, fetchSpecialties } from '@/api/catalogs';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { Icon } from '@/components/Icon';
import { SignaturePad } from '@/components/SignaturePad';
import { ThemePicker } from '@/features/settings/ThemePicker';
import { initials } from '@/lib/theme';
import { t } from '@/i18n/es';
import type { DoctorProfile, Specialty } from '@/api/types';

const schema = z.object({
  name: z.string().min(1, 'Requerido'),
  honorific: z.enum(['', 'Dr.', 'Dra.']).optional(),
  phone: z.string().optional(),
  city: z.string().optional(),
  email: z.string().email('Correo no válido').optional().or(z.literal('')),
  medical_license: z.string().optional(),
  acess_code: z.string().optional(),
  senescyt_registration: z.string().optional(),
  uses_physical_stamp_signature: z.boolean().optional(),
});
type FormValues = z.infer<typeof schema>;

export function ProfilePage() {
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);
  const [saved, setSaved] = useState(false);

  const { data, isLoading } = useQuery({ queryKey: ['profile'], queryFn: fetchProfile });

  const { register, handleSubmit, reset, formState } = useForm<FormValues>({ resolver: zodResolver(schema) });

  useEffect(() => {
    if (data) {
      reset({
        name: data.name,
        honorific: data.honorific ?? '',
        phone: data.phone ?? '',
        city: data.city ?? '',
        email: data.email ?? '',
        medical_license: data.medical_license ?? '',
        acess_code: data.acess_code ?? '',
        senescyt_registration: data.senescyt_registration ?? '',
        uses_physical_stamp_signature: data.uses_physical_stamp_signature,
      });
    }
  }, [data, reset]);

  const mutation = useMutation({
    // An empty choice is «not chosen yet»: the app asks again before the next document.
    mutationFn: (values: FormValues) => updateProfile({ ...values, honorific: values.honorific || null }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['profile'] });
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
    },
  });

  if (isLoading || !data) return <Spinner label={t.common.loading} />;

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Perfil Profesional</h1>
          <p>Tus datos como médico tratante</p>
        </div>
      </div>

      <div className="grid g-3">
        <PhotoCard profile={data} onToast={toast} />

        <Card style={{ gridColumn: 'span 2' }}>
          <form className="form-grid" onSubmit={handleSubmit((v) => mutation.mutate(v))}>
            <div className="full">
              <label className="field-label">Nombre completo</label>
              <input className="field" {...register('name')} />
              {formState.errors.name && <p className="field-error">{formState.errors.name.message}</p>}
            </div>
            <div>
              <label className="field-label" htmlFor="profile-honorific">Firmo como</label>
              <select id="profile-honorific" className="field" {...register('honorific')}>
                <option value="">Sin elegir (sale «Dr.»)</option>
                <option value="Dr.">Dr.</option>
                <option value="Dra.">Dra.</option>
              </select>
            </div>
            <label className="profile-stamp-option full">
              <input type="checkbox" {...register('uses_physical_stamp_signature')} />
              <span><strong>Firmo con sello físico</strong><small>Las recetas, órdenes y certificados dejarán un espacio para tu sello y firma; no imprimirán tus datos profesionales.</small></span>
            </label>
            <div>
              <label className="field-label">Teléfono</label>
              <input className="field" {...register('phone')} />
            </div>
            <div>
              <label className="field-label">Ciudad</label>
              <input className="field" {...register('city')} />
            </div>
            <div>
              <label className="field-label">Correo</label>
              <input className="field" {...register('email')} />
              {formState.errors.email && <p className="field-error">{formState.errors.email.message}</p>}
            </div>
            <div>
              <label className="field-label">Registro profesional MSP</label>
              <input className="field mono" placeholder="Licencia / registro MSP" {...register('medical_license')} />
            </div>
            <div>
              <label className="field-label">Registro ACESS</label>
              <input className="field mono" placeholder="Calificación del profesional (ACESS)" {...register('acess_code')} />
            </div>
            <div>
              <label className="field-label">Registro SENESCYT</label>
              <input className="field mono" placeholder="N° de registro del título (SENESCYT)" {...register('senescyt_registration')} />
            </div>
            <p className="muted full" style={{ fontSize: 12, margin: '0 0 4px' }}>
              Estos registros se imprimen junto a tu firma en recetas, órdenes y certificados.
            </p>
            <div className="full row between">
              <span className="muted" style={{ fontSize: 12.5 }}>
                {saved ? 'Cambios guardados ✓' : mutation.isError ? 'No se pudo guardar.' : ''}
              </span>
              <button className="btn primary" type="submit" disabled={mutation.isPending}>
                {mutation.isPending ? 'Guardando…' : 'Guardar cambios'}
              </button>
            </div>
          </form>
        </Card>
      </div>

      <SpecialtiesCard profile={data} onToast={toast} />

      <Card style={{ marginTop: 20 }}>
        <div className="card-head" style={{ padding: '0 0 14px', borderBottom: '1px solid var(--border)' }}>
          <div>
            <h3>Apariencia</h3>
            <span className="sub">Elige el estilo visual del panel — se guarda en este navegador</span>
          </div>
        </div>
        <ThemePicker />
      </Card>

      <SignatureCard profile={data} onToast={toast} />
      <SessionsCard onToast={toast} />
    </>
  );
}

/**
 * Closing every session, for the case this exists for: a phone that was lost or
 * stolen. Until this button existed the only way to evict a session someone no
 * longer controlled was to reset the password — a strange thing to ask of a
 * doctor whose password was never the problem.
 */
function SessionsCard({ onToast }: { onToast: (message: string) => void }) {
  const logout = useAuthStore((state) => state.logout);
  const navigate = useNavigate();
  const [working, setWorking] = useState(false);

  const closeEverywhere = async () => {
    setWorking(true);
    try {
      await logoutEverywhere();
      onToast('Cerramos la sesión en todos los dispositivos.');
      // This device's token is revoked too, on purpose: "everywhere" that
      // quietly spared the device you pressed it on would not be everywhere.
      logout();
      navigate('/login', { replace: true });
    } catch {
      onToast('No pudimos cerrar las sesiones. Vuelve a intentarlo.');
    } finally {
      setWorking(false);
    }
  };

  return (
    <Card className="stack" style={{ marginTop: 20 }}>
      <div className="card-head" style={{ padding: '0 0 14px', borderBottom: '1px solid var(--border)' }}>
        <div>
          <h3>Sesiones abiertas</h3>
          <span className="sub">Si perdiste un dispositivo, cierra la sesión en todos</span>
        </div>
      </div>
      <p className="muted" style={{ fontSize: 12.5, lineHeight: 1.55, margin: 0 }}>
        Se cerrará la sesión en todos los dispositivos, incluido este. Tendrás que volver a iniciar sesión.
        Tu contraseña no cambia.
      </p>
      <button type="button" className="btn" onClick={() => void closeEverywhere()} disabled={working}>
        <Icon name="logout" size={16} />
        {working ? 'Cerrando…' : 'Cerrar sesión en todos los dispositivos'}
      </button>
    </Card>
  );
}

/** Order the doctor's saved specialties primary-first so the first chip is the principal one. */
function initialSelection(profile: DoctorProfile): number[] {
  const saved = profile.specialties ?? [];
  const primaryFirst = [...saved].sort((a, b) => Number(b.is_primary) - Number(a.is_primary));
  return primaryFirst.map((s) => s.id);
}

function SpecialtiesCard({ profile, onToast }: { profile: DoctorProfile; onToast: (m: string, k?: 'success' | 'error') => void }) {
  const qc = useQueryClient();
  const setIdentity = useAuthStore((s) => s.setIdentity);
  const { data: catalog, isLoading } = useQuery({ queryKey: ['specialties'], queryFn: fetchSpecialties });

  const [selected, setSelected] = useState<number[]>(() => initialSelection(profile));
  const [adding, setAdding] = useState(false);
  const [newName, setNewName] = useState('');

  // Keep the local selection in sync when the profile is refetched (e.g. after save).
  useEffect(() => {
    setSelected(initialSelection(profile));
  }, [profile]);

  // Order of selection matters — the first one checked is the primary specialty
  // (printed on prescriptions), so append/remove rather than sort.
  const toggle = (id: number) => {
    setSelected((prev) => (prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]));
  };

  const create = useMutation({
    mutationFn: (name: string) => createSpecialty(name),
    onSuccess: (specialty) => {
      qc.setQueryData<Specialty[]>(['specialties'], (prev) => {
        const withoutDupe = (prev ?? []).filter((s) => s.id !== specialty.id);
        return [...withoutDupe, specialty].sort((a, b) => a.name.localeCompare(b.name));
      });
      setSelected((prev) => (prev.includes(specialty.id) ? prev : [...prev, specialty.id]));
      setAdding(false);
      setNewName('');
    },
    onError: () => onToast('No se pudo agregar la especialidad.', 'error'),
  });

  const save = useMutation({
    mutationFn: () => updateProfile({ specialty_ids: selected }),
    onSuccess: async () => {
      qc.invalidateQueries({ queryKey: ['profile'] });
      // Refresh the auth-store doctor so the sidebar/topbar specialty updates too.
      qc.invalidateQueries({ queryKey: ['me'] });
      const me = await fetchMe();
      setIdentity({ user: me.user, doctor: me.doctor, staffProfile: me.staff_profile });
      onToast('Especialidades actualizadas.', 'success');
    },
    onError: () => onToast('No se pudieron guardar las especialidades.', 'error'),
  });

  const dirty =
    JSON.stringify(selected) !== JSON.stringify(initialSelection(profile));

  return (
    <Card className="stack" style={{ marginTop: 20 }}>
      <div className="card-head" style={{ padding: '0 0 14px', borderBottom: '1px solid var(--border)' }}>
        <div>
          <h3>Especialidades</h3>
          <span className="sub">
            Marca todas las que ejerces. La primera es tu <b>especialidad principal</b> — la que aparece en tus recetas.
          </span>
        </div>
      </div>

      {!adding ? (
        <>
          <div
            className="stack"
            style={{
              gap: 2,
              maxHeight: 260,
              overflowY: 'auto',
              border: '1px solid var(--border)',
              borderRadius: 10,
              background: 'var(--surface-2)',
              padding: 6,
            }}
          >
            {isLoading && <p className="muted" style={{ fontSize: 13, padding: '4px 8px' }}>Cargando…</p>}
            {catalog?.map((s) => {
              const checked = selected.includes(s.id);
              const isPrimary = checked && selected[0] === s.id;
              return (
                <label
                  key={s.id}
                  className="row"
                  style={{
                    gap: 8,
                    padding: '6px 8px',
                    borderRadius: 8,
                    cursor: 'pointer',
                    fontSize: 13.5,
                    background: checked ? 'var(--accent-soft)' : 'transparent',
                  }}
                >
                  <input type="checkbox" checked={checked} onChange={() => toggle(s.id)} />
                  <span style={{ flex: 1 }}>{s.name}</span>
                  {isPrimary && (
                    <span className="badge accent plain" style={{ fontSize: 10 }}>
                      Principal
                    </span>
                  )}
                </label>
              );
            })}
          </div>

          <button
            type="button"
            className="btn ghost sm"
            style={{ alignSelf: 'flex-start' }}
            onClick={() => setAdding(true)}
          >
            <Icon name="plus" size={14} /> ¿No está tu especialidad? Agrégala
          </button>

          <div className="row between">
            <span className="muted" style={{ fontSize: 12.5 }}>
              {selected.length === 0 ? 'Selecciona al menos una especialidad.' : ''}
            </span>
            <button
              className="btn primary"
              type="button"
              disabled={!dirty || selected.length === 0 || save.isPending}
              onClick={() => save.mutate()}
            >
              {save.isPending ? 'Guardando…' : 'Guardar especialidades'}
            </button>
          </div>
        </>
      ) : (
        <div className="stack" style={{ gap: 12 }}>
          <div>
            <label htmlFor="new_specialty" className="field-label">
              Nombre de la especialidad
            </label>
            <input
              id="new_specialty"
              className="field"
              autoFocus
              placeholder="Ej: Cirugía Cardiovascular"
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
            />
          </div>
          <div className="row" style={{ gap: 8 }}>
            <button
              type="button"
              className="btn primary sm"
              disabled={!newName.trim() || create.isPending}
              onClick={() => create.mutate(newName.trim())}
            >
              {create.isPending ? 'Agregando…' : 'Agregar y marcar'}
            </button>
            <button
              type="button"
              className="btn ghost sm"
              onClick={() => {
                setAdding(false);
                setNewName('');
              }}
            >
              Cancelar
            </button>
          </div>
        </div>
      )}
    </Card>
  );
}

function PhotoCard({ profile, onToast }: { profile: DoctorProfile; onToast: (m: string, k?: 'success' | 'error') => void }) {
  const qc = useQueryClient();
  const setIdentity = useAuthStore((s) => s.setIdentity);
  const fileRef = useRef<HTMLInputElement>(null);

  // Refresh the auth-store doctor too so the topbar avatar updates immediately.
  const refreshSession = async () => {
    qc.invalidateQueries({ queryKey: ['profile'] });
    qc.invalidateQueries({ queryKey: ['me'] });
    const me = await fetchMe();
    setIdentity({ user: me.user, doctor: me.doctor, staffProfile: me.staff_profile });
  };

  const upload = useMutation({
    mutationFn: (file: File) => uploadPhoto(file),
    onSuccess: async () => {
      await refreshSession();
      onToast('Foto de perfil actualizada.', 'success');
    },
    onError: () => onToast('No se pudo subir la foto.', 'error'),
  });
  const remove = useMutation({
    mutationFn: () => deletePhoto(),
    onSuccess: refreshSession,
  });

  return (
    <Card className="stack" style={{ alignItems: 'center', textAlign: 'center' }}>
      <div className="profile-photo">
        {profile.photo_url ? (
          <img src={profile.photo_url} alt="Foto de perfil" />
        ) : (
          <span className="avatar" style={{ width: '100%', height: '100%', fontSize: 28, borderRadius: '50%' }}>
            {initials(profile.name)}
          </span>
        )}
        <button className="photo-edit" onClick={() => fileRef.current?.click()} aria-label="Cambiar foto" disabled={upload.isPending}>
          <Icon name="edit" size={14} />
        </button>
        <input
          ref={fileRef}
          type="file"
          accept="image/png,image/jpeg,image/webp"
          hidden
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) upload.mutate(file);
            e.target.value = '';
          }}
        />
      </div>
      <div>
        <b style={{ fontSize: 16 }}>{profile.name}</b>
        <div className="muted">{profile.specialty ?? 'Médico'}</div>
      </div>
      <div className="row wrap" style={{ justifyContent: 'center' }}>
        <span className="badge accent">CI {profile.id_number ?? '—'}</span>
        <span className={`badge ${profile.has_signature ? 'green' : 'amber'}`}>
          {profile.has_signature ? 'Firma lista' : 'Sin firma'}
        </span>
      </div>
      {profile.has_photo && (
        <button className="btn sm ghost-danger" onClick={() => remove.mutate()} disabled={remove.isPending}>
          <Icon name="trash" size={14} /> Quitar foto
        </button>
      )}
    </Card>
  );
}

function SignatureCard({ profile, onToast }: { profile: DoctorProfile; onToast: (m: string, k?: 'success' | 'error') => void }) {
  const qc = useQueryClient();
  const fileRef = useRef<HTMLInputElement>(null);
  const [mode, setMode] = useState<'draw' | 'upload'>('draw');

  const onSaved = () => {
    qc.invalidateQueries({ queryKey: ['profile'] });
    onToast('Firma guardada.', 'success');
  };

  const upload = useMutation({
    mutationFn: (file: File) => uploadSignature(file),
    onSuccess: onSaved,
    onError: () => onToast('No se pudo guardar la firma.', 'error'),
  });
  const remove = useMutation({
    mutationFn: () => deleteSignature(),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['profile'] }),
  });

  return (
    <Card className="stack" style={{ marginTop: 20 }}>
      <div className="card-head" style={{ padding: '0 0 16px' }}>
        <div>
          <h3>Firma del médico</h3>
          <span className="sub">Se estampa al pie de las recetas que generas (PDF)</span>
        </div>
        <div className="pill-row">
          <button className={`pill ${mode === 'draw' ? 'active' : ''}`} onClick={() => setMode('draw')}>Dibujar</button>
          <button className={`pill ${mode === 'upload' ? 'active' : ''}`} onClick={() => setMode('upload')}>Subir imagen</button>
        </div>
      </div>

      <div className="alert blue">
        <span className="ai"><Icon name="file" size={16} /></span>
        <div>
          <b>Distinta a la firma electrónica SRI</b>
          <p>
            Esta es tu firma manuscrita para las recetas. La <b>firma electrónica</b> (certificado .p12 del BCE)
            para facturar electrónicamente se configura aparte en <b>Ajustes → Facturación SRI</b>.
          </p>
        </div>
      </div>

      <div className="grid g-2" style={{ alignItems: 'start' }}>
        <div className="stack" style={{ gap: 10 }}>
          {mode === 'draw' ? (
            <SignaturePad onSave={(file) => upload.mutate(file)} saving={upload.isPending} />
          ) : (
            <div className="stack" style={{ gap: 10 }}>
              <div className="sig-upload-drop" onClick={() => fileRef.current?.click()}>
                <Icon name="upload" size={22} />
                <span>Selecciona una imagen PNG / JPG de tu firma</span>
              </div>
              <input
                ref={fileRef}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                hidden
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (file) upload.mutate(file);
                  e.target.value = '';
                }}
              />
            </div>
          )}
        </div>

        <div className="stack" style={{ gap: 10 }}>
          <div className="field-label">Vista previa en la receta</div>
          <div className="sig-preview">
            {profile.signature_url ? (
              <img src={profile.signature_url} alt="Firma del médico" />
            ) : (
              <span className="muted" style={{ fontSize: 12.5 }}>Aún no has guardado una firma</span>
            )}
            <span className="sig-preview-line" />
            <span className="sig-preview-name">{profile.name}</span>
          </div>
          {profile.has_signature && (
            <button className="btn sm ghost-danger align-start" onClick={() => remove.mutate()} disabled={remove.isPending}>
              <Icon name="trash" size={14} /> Quitar firma
            </button>
          )}
        </div>
      </div>
    </Card>
  );
}
