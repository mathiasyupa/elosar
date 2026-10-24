import { CAPTURE_SLOTS } from './landingData';
import { RevealOnScroll } from './RevealOnScroll';

export function ProductCaptureGallery() {
  return (
    <RevealOnScroll className="capture-gallery" variant="captures">
      {CAPTURE_SLOTS.map((capture, index) => (
        <figure className="capture-slot" key={capture.title}>
          <div className="capture-slot-frame">
            <header>
              <span aria-hidden="true"><i /><i /><i /></span>
              <small>Elosar</small>
            </header>
            {/* Las capturas son 1600×1000; el marco recorta 36 px por abajo para
                dejar sitio a la cabecera, así que el anclaje va arriba. */}
            <img src={capture.src} alt={capture.alt} width={1600} height={1000} loading="lazy" decoding="async" />
          </div>
          <figcaption>
            <span>0{index + 1}</span>
            <div>
              <strong>{capture.title}</strong>
              <p>{capture.description}</p>
            </div>
          </figcaption>
        </figure>
      ))}
    </RevealOnScroll>
  );
}
