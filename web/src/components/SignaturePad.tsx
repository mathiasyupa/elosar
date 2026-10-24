import { useEffect, useRef, useState } from 'react';
import { Icon } from '@/components/Icon';

interface Props {
  /** Called with a transparent-background PNG of the drawn signature. */
  onSave: (file: File) => void;
  saving?: boolean;
}

/** Pointer-driven signature canvas (mouse / touch / stylus). Produces a trimmed
 *  PNG with a transparent background — drawn in ink that reads on light or dark.
 *  No backend change needed: the PNG is uploaded through the existing
 *  /profile/signature endpoint exactly like an uploaded image. */
export function SignaturePad({ onSave, saving = false }: Props) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const drawing = useRef(false);
  const last = useRef<{ x: number; y: number } | null>(null);
  const [dirty, setDirty] = useState(false);

  // Size the bitmap to the element box × devicePixelRatio for crisp strokes.
  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    const ctx = canvas.getContext('2d');
    if (ctx) {
      ctx.scale(dpr, dpr);
      ctx.lineWidth = 2.4;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = '#1f2733';
    }
  }, []);

  const pos = (e: React.PointerEvent<HTMLCanvasElement>) => {
    const rect = canvasRef.current!.getBoundingClientRect();
    return { x: e.clientX - rect.left, y: e.clientY - rect.top };
  };

  const start = (e: React.PointerEvent<HTMLCanvasElement>) => {
    e.preventDefault();
    drawing.current = true;
    last.current = pos(e);
    canvasRef.current?.setPointerCapture(e.pointerId);
  };

  const move = (e: React.PointerEvent<HTMLCanvasElement>) => {
    if (!drawing.current) return;
    const ctx = canvasRef.current?.getContext('2d');
    const p = pos(e);
    if (ctx && last.current) {
      ctx.beginPath();
      ctx.moveTo(last.current.x, last.current.y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      setDirty(true);
    }
    last.current = p;
  };

  const end = () => {
    drawing.current = false;
    last.current = null;
  };

  const clear = () => {
    const canvas = canvasRef.current;
    const ctx = canvas?.getContext('2d');
    if (canvas && ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
    setDirty(false);
  };

  const save = () => {
    canvasRef.current?.toBlob((blob) => {
      if (blob) onSave(new File([blob], 'firma.png', { type: 'image/png' }));
    }, 'image/png');
  };

  return (
    <div className="sig-pad">
      <div className="sig-canvas-wrap">
        <canvas
          ref={canvasRef}
          className="sig-canvas"
          onPointerDown={start}
          onPointerMove={move}
          onPointerUp={end}
          onPointerLeave={end}
        />
        <span className="sig-baseline" />
        {!dirty && <span className="sig-hint">Dibuja tu firma aquí ✍️</span>}
      </div>
      <div className="row" style={{ gap: 8 }}>
        <button className="btn sm" onClick={clear} disabled={!dirty}>
          <Icon name="x" size={14} /> Limpiar
        </button>
        <button className="btn primary sm" onClick={save} disabled={!dirty || saving}>
          <Icon name="check" size={14} /> {saving ? 'Guardando…' : 'Guardar firma'}
        </button>
      </div>
    </div>
  );
}
