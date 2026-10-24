import { Link } from 'react-router-dom';
import { LegalLayout, LegalSection } from './LegalLayout';
import { CANCELLATION_GRACE_DAYS, LEGAL_ENTITY } from './legalData';

/**
 * Privacy policy, written against the LOPDP's duty to inform (art. 12).
 *
 * The structural fact this document exists to state clearly is that Elosar wears
 * two hats: CONTROLLER of the professional's own account data, and PROCESSOR of the
 * patient records the professional puts in. Blurring that would misrepresent who a
 * patient should turn to — and the answer is their doctor, not us.
 */
export function PrivacyPage() {
  return (
    <LegalLayout
      title="Política de privacidad"
      lead="Qué datos trata Elosar, para qué, con quién se comparten, cuánto se conservan y qué puedes exigir en cualquier momento."
    >
      <LegalSection n={1} title="Quién responde por tus datos">
        <p>
          <strong>{LEGAL_ENTITY.name}</strong>, {LEGAL_ENTITY.role}, responsable del tratamiento.
        </p>
        <ul className="legal-list">
          <li>Domicilio: {LEGAL_ENTITY.address} (Ecuador)</li>
          <li>
            Correo electrónico: <a href={`mailto:${LEGAL_ENTITY.email}`}>{LEGAL_ENTITY.email}</a>
          </li>
          <li>Teléfono: {LEGAL_ENTITY.phone}</li>
        </ul>
        <p className="legal-note">
          Elosar no ha designado delegado de protección de datos personales. El contacto para
          cualquier asunto de privacidad es la dirección de arriba.
        </p>
      </LegalSection>

      <LegalSection n={2} title="Dos papeles distintos, y conviene no confundirlos">
        <p>
          Esta es la parte más importante del documento.
        </p>
        <p>
          <strong>Sobre los datos de tu cuenta profesional</strong> —tu nombre, cédula, correo,
          teléfono, especialidad, ciudad, tu firma— Elosar es el{' '}
          <strong>responsable del tratamiento</strong>. Decide para qué se usan y responde por
          ellos.
        </p>
        <p>
          <strong>Sobre los datos clínicos de tus pacientes</strong> —historias, diagnósticos,
          recetas, resultados, imágenes— el responsable eres <strong>tú</strong>, el profesional o
          el establecimiento de salud. Elosar actúa como <strong>encargado del tratamiento</strong>:
          los guarda y los procesa por cuenta tuya y siguiendo tus instrucciones, y no los usa para
          fines propios.
        </p>
        <p>
          La consecuencia práctica: un paciente que quiera acceder, rectificar o eliminar su
          información debe dirigirse a <strong>su médico o a su clínica</strong>, no a Elosar.
          Nosotros no podemos decidir sobre una historia clínica que no es nuestra.
        </p>
      </LegalSection>

      <LegalSection n={3} title="Qué datos se tratan">
        <p>
          <strong>De ti, como profesional o miembro del equipo:</strong> nombre, cédula, correo
          electrónico, teléfono, ciudad, especialidades, número de registro profesional, firma
          gráfica y fotografía si las cargas, y los datos de tu consultorio o clínica —incluido el
          membrete y la información fiscal que uses para facturar—.
        </p>
        <p>
          <strong>De uso del servicio:</strong> fecha y hora de tus accesos, acciones realizadas
          sobre historias clínicas y facturas —el registro de auditoría que un sistema médico está
          obligado a poder mostrar—, y datos técnicos mínimos como la dirección IP.
        </p>
        <p>
          <strong>De tus pacientes, por cuenta tuya:</strong> datos de identificación y contacto, y{' '}
          <strong>datos de salud</strong> —antecedentes, alergias, diagnósticos, signos vitales,
          prescripciones, órdenes, vacunas, documentos adjuntos—. La ley los considera una{' '}
          <strong>categoría especial</strong> y reciben el trato reforzado que se describe en el
          punto 8.
        </p>
      </LegalSection>

      <LegalSection n={4} title="Para qué se tratan, y con qué base legal">
        <ul className="legal-list">
          <li>
            <strong>Prestarte el servicio</strong> que contrataste y darte soporte — base legal:
            la ejecución del contrato entre tú y Elosar.
          </li>
          <li>
            <strong>Crear y proteger tu cuenta</strong>: verificar tu correo, permitirte recuperar
            el acceso, cerrar sesiones, detectar usos indebidos — ejecución del contrato e interés
            legítimo en la seguridad del servicio.
          </li>
          <li>
            <strong>Guardar y procesar la información clínica</strong> de tus pacientes — por
            cuenta tuya, como encargado, bajo tus instrucciones.
          </li>
          <li>
            <strong>Emitir y transmitir comprobantes electrónicos al SRI</strong> cuando uses la
            facturación — cumplimiento de una obligación legal tributaria que recae sobre ti.
          </li>
          <li>
            <strong>Avisarte de cosas del servicio</strong>: recordatorios, cambios importantes,
            incidencias — ejecución del contrato.
          </li>
          <li>
            <strong>Mantener el servicio en pie</strong>: copias de seguridad y detección de
            errores — interés legítimo en la continuidad y la seguridad.
          </li>
        </ul>
        <p>
          Elosar <strong>no</strong> usa tus datos ni los de tus pacientes para publicidad, no los
          vende, no los cede con fines comerciales y no los emplea para entrenar sistemas de
          inteligencia artificial.
        </p>
      </LegalSection>

      <LegalSection n={5} title="De dónde salen los datos">
        <p>
          Tus datos los entregas tú al registrarte y al usar el producto. Los datos de tus pacientes
          los introduces tú, o los introduce el personal de tu clínica, o el propio paciente cuando
          se los facilita a tu equipo. Elosar no compra bases de datos ni obtiene información de
          terceros.
        </p>
      </LegalSection>

      <LegalSection n={6} title="Con quién se comparten">
        <p>Solo con quien hace falta para que el servicio funcione:</p>
        <ul className="legal-list">
          <li>
            <strong>Proveedor de infraestructura y base de datos</strong>, donde se ejecuta la
            aplicación y se almacena la información.
          </li>
          <li>
            <strong>Proveedor de almacenamiento</strong>, donde se guardan las copias de seguridad
            cifradas, separadas del proveedor principal para que un fallo de este no se lleve todo.
          </li>
          <li>
            <strong>Proveedor de correo electrónico</strong>, para enviarte verificaciones,
            recuperación de contraseña y recordatorios.
          </li>
          <li>
            <strong>Servicio de detección de errores</strong>, configurado expresamente para{' '}
            <strong>descartar datos de pacientes</strong>: no recibe cuerpos de peticiones, ni
            parámetros de consultas a la base de datos, ni registros de la aplicación.
          </li>
          <li>
            <strong>Servicio de Rentas Internas</strong>, cuando emites un comprobante electrónico
            y en la medida que la normativa tributaria exige.
          </li>
        </ul>
        <p>
          Estos proveedores actúan como encargados, solo pueden tratar los datos conforme a las
          instrucciones recibidas, y están obligados a medidas de seguridad y confidencialidad.
        </p>
      </LegalSection>

      <LegalSection n={7} title="Los datos salen del Ecuador">
        <p>
          Hay que decirlo con claridad: <strong>los servidores están fuera del país</strong>,
          principalmente en los Estados Unidos de América. Usar Elosar implica una{' '}
          <strong>transferencia internacional</strong> de los datos, incluidos los de salud.
        </p>
        <p>
          Los proveedores se eligen entre los que ofrecen garantías contractuales de protección y
          medidas de seguridad reconocidas en la industria, y la información viaja siempre cifrada.
          Aun así, es un hecho que debes conocer antes de aceptar, y que tú a tu vez debes informar
          a tus pacientes: tú eres el responsable de sus datos.
        </p>
      </LegalSection>

      <LegalSection n={8} title="Cómo se protegen">
        <p>Medidas que están efectivamente implementadas, no una lista de intenciones:</p>
        <ul className="legal-list">
          <li>Cifrado del tráfico entre tu navegador y el servidor.</li>
          <li>
            <strong>Aislamiento entre espacios de trabajo</strong>: cada consultorio o clínica solo
            alcanza sus propios datos, y esa separación está verificada con pruebas automáticas que
            simulan intentos de acceso cruzado.
          </li>
          <li>
            <strong>Archivos privados</strong>: firmas, fotografías y membretes no son públicos; se
            sirven mediante enlaces firmados que caducan.
          </li>
          <li>Contraseñas almacenadas cifradas, nunca en texto plano.</li>
          <li>
            Verificación de correo obligatoria; restablecer la contraseña revoca todas las sesiones;
            puedes cerrar sesión en todos los dispositivos desde tu perfil.
          </li>
          <li>Segundo factor obligatorio en las cuentas de administración de la plataforma.</li>
          <li>
            <strong>Copias de seguridad diarias y cifradas</strong>, guardadas fuera del proveedor
            principal, y cuyo procedimiento de restauración se prueba periódicamente.
          </li>
          <li>Registro de auditoría de las acciones sobre información clínica y financiera.</li>
        </ul>
        <p>
          Ninguna medida elimina el riesgo por completo. Si ocurriera una vulneración que afecte a
          tus datos, se te comunicará y se notificará a la autoridad en los plazos que la ley
          establece.
        </p>
      </LegalSection>

      <LegalSection n={9} title="Cuánto tiempo se conservan">
        <ul className="legal-list">
          <li>
            <strong>Mientras tengas cuenta activa</strong>, se conserva lo necesario para prestarte
            el servicio.
          </li>
          <li>
            <strong>Tras cancelar</strong>, tu espacio permanece accesible{' '}
            <strong>{CANCELLATION_GRACE_DAYS} días</strong> para que exportes tu información.
            Cumplido el plazo puede eliminarse de forma definitiva.
          </li>
          <li>
            <strong>La historia clínica</strong> tiene un plazo de conservación propio fijado por la
            normativa de salud, y esa obligación es <strong>tuya</strong>, no de Elosar. Por eso el
            producto te permite llevártela.
          </li>
          <li>
            <strong>Comprobantes electrónicos y registros contables</strong>: el tiempo que exija la
            normativa tributaria.
          </li>
        </ul>
      </LegalSection>

      <LegalSection n={10} title="Qué puedes exigir">
        <p>
          Sobre tus propios datos puedes ejercer los derechos de <strong>acceso</strong>,{' '}
          <strong>rectificación</strong>, <strong>eliminación</strong>,{' '}
          <strong>oposición</strong>, <strong>portabilidad</strong>, y a no ser objeto de decisiones
          basadas únicamente en tratamientos automatizados. También puedes{' '}
          <strong>suspender temporalmente</strong> el tratamiento mientras se resuelve una solicitud.
        </p>
        <p>
          Se ejercen escribiendo a{' '}
          <a href={`mailto:${LEGAL_ENTITY.email}`}>{LEGAL_ENTITY.email}</a>. Se pedirá comprobar tu
          identidad —lo mínimo imprescindible para no entregarle tus datos a otra persona— y se
          responderá en los plazos que fija la ley. Ejercerlos es gratuito.
        </p>
        <p>
          Si tu solicitud es sobre datos <strong>de un paciente</strong>, corresponde dirigirla al
          médico o a la clínica que lo atiende, por lo explicado en el punto 2. Si nos llega una,
          la trasladaremos y te lo haremos saber.
        </p>
      </LegalSection>

      <LegalSection n={11} title="Revocar el consentimiento, y qué pasa si no entregas datos">
        <p>
          Cuando el tratamiento se base en tu consentimiento, puedes revocarlo en cualquier momento,
          con la misma facilidad con que lo diste, sin que ello afecte a la licitud del tratamiento
          anterior.
        </p>
        <p>
          Algunos datos son <strong>imprescindibles</strong>: sin correo electrónico no hay forma de
          verificar la cuenta ni de devolverte el acceso; sin los datos clínicos que introduces no
          hay historia que guardar. Si no los entregas, no es que el servicio funcione peor — es que
          no puede prestarse.
        </p>
      </LegalSection>

      <LegalSection n={12} title="Reclamar ante la autoridad">
        <p>
          Si consideras que tus datos no están siendo tratados correctamente, puedes presentar una
          reclamación ante la <strong>Superintendencia de Protección de Datos Personales</strong> del
          Ecuador. Te agradeceremos que nos escribas primero —normalmente se resuelve antes—, pero
          no es un requisito para acudir a ella.
        </p>
      </LegalSection>

      <LegalSection n={13} title="Menores de edad">
        <p>
          Elosar es una herramienta profesional: no está dirigida a menores y no se crean cuentas
          para ellos. Cosa distinta es que un paciente atendido sea menor de edad; en ese caso, el
          consentimiento y la información hacia su representante legal corresponden al profesional
          que lo atiende, que es el responsable de esos datos.
        </p>
      </LegalSection>

      <LegalSection n={14} title="Almacenamiento en tu navegador">
        <p>
          Elosar no usa cookies de publicidad ni de seguimiento de terceros. Guarda en tu navegador
          lo imprescindible para que la aplicación funcione: tu sesión y tus preferencias de
          apariencia —tema claro u oscuro y variante de color—. Nada de eso se comparte con nadie.
        </p>
      </LegalSection>

      <LegalSection n={15} title="Cambios en esta política">
        <p>
          Si el texto cambia, cambia su número de versión y la fecha de actualización que aparece
          arriba. Los cambios importantes se avisan por correo, y cada cuenta conserva el registro de
          qué versión aceptó. Los <Link to="/terminos">términos de servicio</Link> siguen el mismo
          criterio.
        </p>
      </LegalSection>
    </LegalLayout>
  );
}
