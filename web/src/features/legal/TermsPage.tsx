import { Link } from 'react-router-dom';
import { LegalLayout, LegalSection } from './LegalLayout';
import { CANCELLATION_GRACE_DAYS, LEGAL_ENTITY } from './legalData';

/**
 * Terms of service, in Spanish because the reader is an Ecuadorian doctor.
 *
 * Written to be read, not to be survived. Where a clause exists to protect Elosar,
 * it says so plainly instead of hiding behind volume — a professional who is about
 * to put patient records in here deserves to understand what they are agreeing to,
 * and a document nobody can read is not consent in any sense that matters.
 */
export function TermsPage() {
  return (
    <LegalLayout
      title="Términos de servicio"
      lead="Las reglas de uso de Elosar: qué te damos, qué esperamos de ti, y qué pasa con tu información si algún día decides irte."
    >
      <LegalSection n={1} title="Quién presta el servicio">
        <p>
          Elosar es operado por <strong>{LEGAL_ENTITY.name}</strong>, {LEGAL_ENTITY.role}, con
          domicilio en {LEGAL_ENTITY.address} (Ecuador), correo{' '}
          <a href={`mailto:${LEGAL_ENTITY.email}`}>{LEGAL_ENTITY.email}</a> y teléfono{' '}
          {LEGAL_ENTITY.phone}. En este documento se le llama «Elosar».
        </p>
        <p>
          Al marcar la casilla de aceptación durante el registro, aceptas estos términos y la{' '}
          <Link to="/privacidad">política de privacidad</Link>. Se guarda la fecha y la versión
          del documento que aceptaste.
        </p>
      </LegalSection>

      <LegalSection n={2} title="Qué es Elosar, y qué no es">
        <p>
          Elosar es un <strong>programa de computadora</strong> para organizar la operación de un
          consultorio o una clínica: agenda, historia clínica, recetas, órdenes, facturación y
          reportes.
        </p>
        <p>
          Elosar <strong>no presta servicios de salud</strong>, no diagnostica, no prescribe y no
          sustituye el criterio del profesional. Las decisiones clínicas son tuyas, y tuya es la
          responsabilidad sobre ellas. Que el sistema permita registrar algo no significa que sea
          clínicamente correcto: eso lo determinas tú.
        </p>
        <p>
          Elosar tampoco es un servicio de custodia documental ni un archivo oficial. La obligación
          legal de conservar la historia clínica es del profesional o del establecimiento de salud.
        </p>
      </LegalSection>

      <LegalSection n={3} title="Quién puede usarlo">
        <p>
          Elosar está dirigido a profesionales de la salud y al personal de sus consultorios o
          clínicas. Para crear una cuenta debes ser mayor de edad, entregar datos veraces y —cuando
          ejerzas una profesión regulada— contar con la habilitación que la ley exija.
        </p>
        <p>
          Las cuentas son <strong>personales</strong>. Compartir credenciales entre varias personas
          rompe el registro de quién hizo qué sobre una historia clínica, que es precisamente lo que
          un sistema médico tiene que poder demostrar.
        </p>
      </LegalSection>

      <LegalSection n={4} title="Tu cuenta y su seguridad">
        <p>
          Antes de entrar por primera vez tienes que confirmar tu correo electrónico: sin una
          dirección que responda, no hay forma de devolverte el acceso si lo pierdes.
        </p>
        <p>
          Eres responsable de tu contraseña y de lo que ocurra bajo tu cuenta. Si crees que alguien
          más entró, o si perdiste un dispositivo, tienes en tu perfil la opción de{' '}
          <strong>cerrar la sesión en todos los dispositivos</strong>, y puedes restablecer tu
          contraseña —lo que también revoca todas las sesiones abiertas—. Avísanos si detectas un
          uso que no reconoces.
        </p>
      </LegalSection>

      <LegalSection n={5} title="Prueba, planes y pagos">
        <p>
          Elosar ofrece un periodo de prueba. Al terminar, el espacio de trabajo queda en{' '}
          <strong>solo lectura</strong> hasta que se active un plan: puedes seguir consultando y
          exportando lo tuyo, pero no registrar información nueva. Nada se borra por vencerse la
          prueba.
        </p>
        <p>
          Los planes y precios vigentes son los publicados en el sitio. En los planes de clínica
          solo ocupan cupo los <strong>médicos que atienden</strong>: recepción, caja, enfermería y
          administración no cuentan.
        </p>
        <p>
          Los valores son en dólares de los Estados Unidos de América y no incluyen los impuestos
          que correspondan. Si cambian los precios, se avisa por correo antes de que apliquen.
        </p>
      </LegalSection>

      <LegalSection n={6} title="Si decides irte">
        <p>
          Puedes cancelar cuando quieras. Tras la cancelación, tu espacio de trabajo permanece
          accesible durante <strong>{CANCELLATION_GRACE_DAYS} días</strong> para que puedas
          descargar tu información.
        </p>
        <p>
          Ese plazo existe por una razón concreta: la historia clínica es tuya y tu obligación de
          conservarla no termina porque dejes de usar un programa. Entregártela es nuestra parte del
          trato, y por eso la exportación no es un adorno del producto.
        </p>
        <p>
          Cumplido el plazo, la información puede eliminarse de forma definitiva. Descárgala antes.
        </p>
      </LegalSection>

      <LegalSection n={7} title="El contenido es tuyo">
        <p>
          Todo lo que registras —pacientes, historias, recetas, facturas, documentos— sigue siendo
          tuyo. Elosar no lo vende, no lo cede a terceros con fines comerciales y no lo usa para
          entrenar sistemas de inteligencia artificial.
        </p>
        <p>
          Elosar lo trata únicamente para prestarte el servicio, siguiendo tus instrucciones, tal
          como se detalla en la <Link to="/privacidad">política de privacidad</Link>.
        </p>
      </LegalSection>

      <LegalSection n={8} title="Facturación electrónica y SRI">
        <p>
          Cuando emites comprobantes electrónicos, Elosar los arma, los firma con{' '}
          <strong>tu</strong> certificado y los transmite al Servicio de Rentas Internas. El emisor
          ante el SRI eres tú: la validez fiscal, la veracidad de lo facturado y la custodia de tu
          certificado son tu responsabilidad.
        </p>
        <p>
          Elosar no responde por rechazos del SRI derivados de datos incorrectos, de un certificado
          caducado, ni por interrupciones de los servicios del propio SRI.
        </p>
      </LegalSection>

      <LegalSection n={9} title="Disponibilidad y cambios">
        <p>
          Elosar se presta «tal como está» y con la mayor continuidad que resulte razonable, pero no
          se garantiza un funcionamiento ininterrumpido ni libre de errores. Puede haber
          mantenimientos, y puede haber fallos.
        </p>
        <p>
          El producto evoluciona: se añaden funciones y ocasionalmente se retiran. Si un cambio
          afecta de forma importante a cómo trabajas, se avisa con antelación por correo.
        </p>
      </LegalSection>

      <LegalSection n={10} title="Límite de responsabilidad">
        <p>
          Elosar no responde por decisiones clínicas, por el contenido que registras, ni por daños
          indirectos, lucro cesante o pérdida de oportunidad. En cualquier caso, la responsabilidad
          total frente a ti se limita a lo que hayas pagado por el servicio en los doce meses
          anteriores al hecho que la origine.
        </p>
        <p>
          Nada de lo anterior excluye la responsabilidad que la ley ecuatoriana no permita excluir,
          incluida la que corresponda por dolo.
        </p>
      </LegalSection>

      <LegalSection n={11} title="Suspensión">
        <p>
          Elosar puede suspender una cuenta que ponga en riesgo la seguridad del servicio o los
          datos de terceros, que incumpla estos términos o que se use para algo ilícito. Salvo que
          el riesgo obligue a actuar de inmediato, se avisa primero y se da oportunidad de
          corregir. Una suspensión no te priva del derecho a exportar tu información.
        </p>
      </LegalSection>

      <LegalSection n={12} title="Cambios en estos términos">
        <p>
          Si el texto cambia, cambia también su número de versión. Los cambios importantes se avisan
          por correo con antelación razonable, y cuando corresponda se te pedirá aceptar la nueva
          versión.
        </p>
        <p>
          Cada cuenta guarda <strong>qué versión aceptó y cuándo</strong>. Así siempre es posible
          saber a qué texto se comprometió cada persona, en lugar de suponerlo.
        </p>
      </LegalSection>

      <LegalSection n={13} title="Ley aplicable">
        <p>
          Estos términos se rigen por la legislación de la República del Ecuador. Cualquier
          controversia se somete a los jueces competentes del Ecuador, sin perjuicio de que
          intentemos resolverla antes escribiéndonos.
        </p>
      </LegalSection>
    </LegalLayout>
  );
}
