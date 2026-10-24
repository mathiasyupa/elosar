import type {
  ClinicalReport,
  DailyReport,
  FinanceEntry,
  PnlReport,
  SettlementReport,
} from '@/api/types';
import type { SheetSpec } from '@/lib/excel';
import { CONSULTATION_TYPE_LABELS } from '@/features/patients/workspace/StructuredExamFields';
import { GENDER_LABELS } from '@/i18n/labels';

/**
 * Sheet builders for the report screens. They mirror what is on screen — same
 * figures, same order, same period — so the spreadsheet can be reconciled
 * against the dashboard line by line.
 */

const METHOD_LABELS: Record<string, string> = {
  cash: 'Efectivo',
  transfer: 'Transferencia',
  card: 'Tarjeta',
  other: 'Otro',
};

/** Arancel categories (English in the schema, Spanish in the workbook). */
const SERVICE_CATEGORY_LABELS: Record<string, string> = {
  general: 'General',
  laboratory: 'Laboratorio',
  imaging: 'Imagenología',
  procedure: 'Procedimientos',
  dental: 'Odontología',
};

const ENTRY_TYPE_LABELS: Record<FinanceEntry['type'], string> = {
  expense: 'Gasto',
  income: 'Otro ingreso',
  doctor_adjustment: 'Descuento a médico',
};

const CATEGORY_LABELS: Record<string, string> = {
  supplies: 'Insumos médicos',
  utilities: 'Servicios básicos',
  rent: 'Alquiler del local',
  maintenance: 'Mantenimiento',
  equipment: 'Equipamiento',
  payroll: 'Personal de apoyo',
  taxes: 'Impuestos y tasas',
  room_rental: 'Alquiler de consultorio',
  equipment_rental: 'Alquiler de equipos',
  advance: 'Adelanto entregado',
  other: 'Otros',
};

const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

/** The day-by-day workbook of the selected period (cash close + cómo se cobró). */
export function dailySheets(daily: DailyReport): SheetSpec[] {
  const scope = daily.scope === 'own' ? 'Solo tus ingresos' : 'Toda la clínica';

  return [
    {
      name: 'Resumen',
      caption: `${scope} · ${daily.label} · generado el ${new Date().toLocaleDateString('es-EC')}`,
      columns: [
        { header: 'Concepto', key: 'concepto', width: 34 },
        { header: 'Valor', key: 'valor', width: 16, format: 'money' },
      ],
      rows: [
        { concepto: `Cobrado en ${daily.label}`, valor: daily.summary.collected },
        { concepto: 'Promedio diario', valor: daily.summary.daily_avg },
        { concepto: 'Proyección de cierre', valor: daily.summary.projection },
        {
          concepto: `Período anterior (${daily.comparison.previous_label})`,
          valor: daily.comparison.previous_to_date,
        },
        { concepto: 'Cobrado hoy', valor: daily.today.collected },
        { concepto: 'Facturado hoy', valor: daily.today.billed },
        { concepto: 'Pendiente de cobro de hoy', valor: daily.today.pending },
      ],
    },
    {
      name: 'Día a día',
      caption: `Cobros por día — ${daily.label}`,
      columns: [
        { header: 'Fecha', key: 'fecha', width: 13, format: 'date' },
        { header: 'Día', key: 'dia', width: 7, format: 'int' },
        { header: 'Día de semana', key: 'semana', width: 15 },
        { header: 'Cobrado', key: 'cobrado', width: 14, format: 'money' },
        { header: 'Facturado', key: 'facturado', width: 14, format: 'money' },
        { header: 'N° de cobros', key: 'cobros', width: 13, format: 'int' },
      ],
      // Days that haven't happened yet would read as real $0 days in a sheet.
      rows: daily.days
        .filter((d) => !d.is_future)
        .map((d) => ({
          fecha: d.date,
          dia: d.day,
          semana: WEEKDAYS[d.weekday],
          cobrado: d.total,
          facturado: d.billed,
          cobros: d.count,
        })),
    },
    {
      name: 'Métodos de pago',
      caption: `Cómo se cobró — ${daily.label}`,
      columns: [
        { header: 'Método', key: 'metodo', width: 18 },
        { header: 'N° de cobros', key: 'cobros', width: 13, format: 'int' },
        { header: 'Total', key: 'total', width: 14, format: 'money' },
      ],
      rows: daily.payment_methods.map((m) => ({
        metodo: METHOD_LABELS[m.method] ?? m.method,
        cobros: m.count,
        total: m.total,
      })),
    },
    {
      name: 'Por día de semana',
      caption: `Promedio cobrado por día de la semana — ${daily.label}`,
      columns: [
        { header: 'Día', key: 'dia', width: 14 },
        { header: 'Promedio', key: 'promedio', width: 14, format: 'money' },
        { header: 'Total', key: 'total', width: 14, format: 'money' },
      ],
      rows: daily.by_weekday.map((w) => ({
        dia: WEEKDAYS[w.weekday],
        promedio: w.avg,
        total: w.total,
      })),
    },
  ];
}

/**
 * Doctor settlement workbook: the per-doctor summary and, on its own tab, the
 * attention-by-attention detail — the sheet a clinic hands each specialist.
 */
export function settlementSheets(data: SettlementReport): SheetSpec[] {
  const period = `${data.period.label} (${data.period.from} a ${data.period.to})`;

  return [
    {
      name: 'Resumen por médico',
      caption: `Reparto clínica–especialista · ${period}`,
      columns: [
        { header: 'Médico', key: 'medico', width: 30 },
        { header: 'Atenciones', key: 'atenciones', width: 12, format: 'int' },
        { header: 'Cobrado', key: 'cobrado', width: 14, format: 'money' },
        { header: 'Comisión %', key: 'porcentaje', width: 12, format: 'percent' },
        { header: 'Comisión', key: 'comision', width: 14, format: 'money' },
        { header: 'Descuentos', key: 'descuentos', width: 14, format: 'money' },
        { header: 'Al médico', key: 'alMedico', width: 14, format: 'money' },
        { header: 'A la clínica', key: 'aLaClinica', width: 14, format: 'money' },
      ],
      rows: [
        ...data.by_doctor.map((d) => ({
          medico: d.name,
          atenciones: d.count,
          cobrado: d.total,
          porcentaje: d.commission_pct,
          comision: d.doctor_amount,
          descuentos: d.adjustments,
          alMedico: d.doctor_net,
          aLaClinica: d.clinic_amount,
        })),
        {
          medico: 'TOTAL',
          atenciones: data.totals.attentions,
          cobrado: data.totals.collected,
          porcentaje: null,
          comision: data.totals.doctor_amount,
          descuentos: data.totals.adjustments,
          alMedico: data.totals.doctor_net,
          aLaClinica: data.totals.clinic_amount,
        },
      ],
    },
    {
      name: 'Detalle por atención',
      caption: `Cada línea cobrada · ${period}`,
      columns: [
        { header: 'Fecha', key: 'fecha', width: 13, format: 'date' },
        { header: 'Médico', key: 'medico', width: 26 },
        { header: 'Paciente', key: 'paciente', width: 30 },
        { header: 'Cédula', key: 'cedula', width: 14 },
        { header: 'Atención', key: 'atencion', width: 34 },
        { header: 'Costo', key: 'costo', width: 13, format: 'money' },
        { header: 'Cobrado', key: 'cobrado', width: 13, format: 'money' },
        { header: 'Pago médico', key: 'pagoMedico', width: 14, format: 'money' },
        { header: 'Neto clínica', key: 'netoClinica', width: 14, format: 'money' },
        { header: 'Factura', key: 'factura', width: 14 },
      ],
      rows: data.detail.map((row) => ({
        fecha: row.date,
        medico: row.doctor_name,
        paciente: row.patient_name,
        cedula: row.patient_id_number,
        atencion: row.service,
        costo: row.price,
        cobrado: row.collected,
        pagoMedico: row.doctor_amount,
        netoClinica: row.clinic_amount,
        factura: row.invoice_number ?? 'Borrador',
      })),
    },
  ];
}

/** The clinic's books: the result, the movements and where the money went. */
export function pnlSheets(data: PnlReport, entries: FinanceEntry[]): SheetSpec[] {
  const period = `${data.period.label} (${data.period.from} a ${data.period.to})`;

  return [
    {
      name: 'Estado de resultados',
      caption: `${period} · generado el ${new Date().toLocaleDateString('es-EC')}`,
      columns: [
        { header: 'Concepto', key: 'concepto', width: 36 },
        { header: 'Valor', key: 'valor', width: 16, format: 'money' },
      ],
      rows: [
        { concepto: 'Cobrado a pacientes', valor: data.collected },
        { concepto: 'Otros ingresos', valor: data.other_income },
        { concepto: 'Ingresos totales', valor: data.income_total },
        { concepto: 'Comisiones a especialistas', valor: `-${data.doctor_commissions}` },
        { concepto: 'Gastos operativos', valor: `-${data.expenses}` },
        { concepto: 'UTILIDAD NETA', valor: data.net_profit },
        { concepto: 'Margen neto (%)', valor: `${data.net_margin_pct}%` },
        { concepto: 'Facturado en el período', valor: data.billed },
        { concepto: 'Por cobrar (cartera total)', valor: data.receivable_total },
      ],
    },
    {
      name: 'Movimientos',
      caption: `Gastos, otros ingresos y descuentos · ${period}`,
      columns: [
        { header: 'Fecha', key: 'fecha', width: 13, format: 'date' },
        { header: 'Tipo', key: 'tipo', width: 20 },
        { header: 'Categoría', key: 'categoria', width: 22 },
        { header: 'Descripción', key: 'descripcion', width: 40 },
        { header: 'Médico', key: 'medico', width: 24 },
        { header: 'Comprobante', key: 'comprobante', width: 18 },
        { header: 'Monto', key: 'monto', width: 14, format: 'money' },
      ],
      rows: entries.map((entry) => ({
        fecha: entry.entry_date,
        tipo: ENTRY_TYPE_LABELS[entry.type],
        categoria: CATEGORY_LABELS[entry.category] ?? entry.category,
        descripcion: entry.description,
        medico: entry.doctor_name,
        comprobante: entry.receipt_number,
        monto: entry.amount,
      })),
    },
    {
      name: 'Evolución mensual',
      caption: 'Seis meses de ingresos, egresos y utilidad',
      columns: [
        { header: 'Mes', key: 'mes', width: 12 },
        { header: 'Cobrado', key: 'cobrado', width: 14, format: 'money' },
        { header: 'Otros ingresos', key: 'otros', width: 14, format: 'money' },
        { header: 'A especialistas', key: 'comisiones', width: 15, format: 'money' },
        { header: 'Gastos', key: 'gastos', width: 14, format: 'money' },
        { header: 'Utilidad neta', key: 'neto', width: 14, format: 'money' },
      ],
      rows: data.series.map((m) => ({
        mes: m.month,
        cobrado: m.collected,
        otros: m.other_income,
        comisiones: m.commissions,
        gastos: m.expenses,
        neto: m.net_profit,
      })),
    },
    {
      name: 'Servicios',
      caption: `Servicios que más ingresan · ${period}`,
      columns: [
        { header: 'Servicio', key: 'servicio', width: 36 },
        { header: 'Ingreso', key: 'ingreso', width: 14, format: 'money' },
        { header: 'Veces', key: 'veces', width: 10, format: 'int' },
        { header: 'Precio promedio', key: 'promedio', width: 16, format: 'money' },
        { header: '% del ingreso', key: 'participacion', width: 14, format: 'percent' },
      ],
      rows: data.top_services.rows.map((s) => ({
        servicio: s.description,
        ingreso: s.total,
        veces: s.count,
        promedio: s.avg,
        participacion: s.share,
      })),
    },
    {
      name: 'Por categoría',
      // "Facturado", como en pantalla: los pagos se registran contra la factura
      // completa, así que no existe un cobrado por categoría que no sea inventado.
      caption: `Facturado por categoría · ${period}`,
      columns: [
        { header: 'Categoría', key: 'categoria', width: 24 },
        { header: 'Facturado', key: 'facturado', width: 14, format: 'money' },
        { header: 'Líneas', key: 'lineas', width: 10, format: 'int' },
        { header: '% del ingreso', key: 'participacion', width: 14, format: 'percent' },
      ],
      rows: data.services_by_category.rows.map((row) => ({
        categoria: row.category
          ? SERVICE_CATEGORY_LABELS[row.category] ?? row.category
          : 'Sin categoría (factura escrita a mano)',
        facturado: row.total,
        lineas: row.count,
        participacion: row.share,
      })),
    },
  ];
}

/** Health-surveillance workbook (morbidity, production, demographics). */
export function clinicalSheets(data: ClinicalReport): SheetSpec[] {
  const period = `Período ${data.period.from} a ${data.period.to}`;

  return [
    {
      name: 'Morbilidad',
      caption: `Principales diagnósticos CIE-10 · ${period}`,
      columns: [
        { header: 'Código CIE-10', key: 'codigo', width: 14 },
        { header: 'Descripción', key: 'descripcion', width: 52 },
        { header: 'Casos', key: 'casos', width: 10, format: 'int' },
      ],
      rows: data.morbidity.map((m) => ({ codigo: m.code, descripcion: m.description, casos: m.total })),
    },
    {
      name: 'Por aparato afecto',
      caption: period,
      columns: [
        { header: 'Sistema / capítulo', key: 'sistema', width: 46 },
        { header: 'Casos', key: 'casos', width: 10, format: 'int' },
      ],
      rows: data.by_system.map((s) => ({ sistema: s.system, casos: s.total })),
    },
    {
      name: 'Atenciones por mes',
      caption: `Total de atenciones en el período: ${data.attentions.total}`,
      columns: [
        { header: 'Mes', key: 'mes', width: 12 },
        { header: 'Atenciones', key: 'atenciones', width: 13, format: 'int' },
      ],
      rows: data.attentions.monthly.map((m) => ({ mes: m.month, atenciones: m.total })),
    },
    {
      name: 'Atenciones por tipo',
      caption: period,
      columns: [
        { header: 'Tipo de consulta', key: 'tipo', width: 24 },
        { header: 'Atenciones', key: 'atenciones', width: 13, format: 'int' },
      ],
      rows: data.attentions.by_type.map((t) => ({
        // The API sends the raw column value, which may predate the current
        // list of consultation types — fall back to it rather than blank out.
        tipo: (CONSULTATION_TYPE_LABELS as Record<string, string>)[t.type] ?? t.type,
        atenciones: t.total,
      })),
    },
    {
      name: 'Atenciones por médico',
      caption: period,
      columns: [
        { header: 'Médico', key: 'medico', width: 30 },
        { header: 'Atenciones', key: 'atenciones', width: 13, format: 'int' },
      ],
      rows: data.attentions.by_doctor.map((d) => ({ medico: d.name, atenciones: d.total })),
    },
    {
      name: 'Demografía',
      caption: `Población de pacientes · nuevos en el período: ${data.demographics.new_patients}, subsecuentes: ${data.demographics.returning_patients}`,
      columns: [
        { header: 'Categoría', key: 'categoria', width: 18 },
        { header: 'Grupo', key: 'grupo', width: 24 },
        { header: 'Pacientes', key: 'pacientes', width: 12, format: 'int' },
      ],
      rows: [
        ...data.demographics.by_gender.map((g) => ({
          categoria: 'Sexo',
          grupo: GENDER_LABELS[g.gender] ?? g.gender,
          pacientes: g.total,
        })),
        ...data.demographics.by_age_group.map((a) => ({
          categoria: 'Grupo de edad',
          grupo: a.group,
          pacientes: a.total,
        })),
        ...data.demographics.by_city.map((c) => ({
          categoria: 'Ciudad',
          grupo: c.city,
          pacientes: c.total,
        })),
      ],
    },
    {
      name: 'Medicamentos',
      caption: `Más prescritos · ${period}`,
      columns: [
        { header: 'Medicamento', key: 'medicamento', width: 36 },
        { header: 'Prescripciones', key: 'prescripciones', width: 15, format: 'int' },
        { header: 'De control', key: 'control', width: 12, format: 'int' },
      ],
      rows: data.medications.map((m) => ({
        medicamento: m.medication,
        prescripciones: m.total,
        control: m.controlled,
      })),
    },
    {
      name: 'Vacunas por recordar',
      caption: `${data.vaccine_reminders.count} recordatorios vencidos o próximos`,
      columns: [
        { header: 'Paciente', key: 'paciente', width: 30 },
        { header: 'Vacuna', key: 'vacuna', width: 26 },
        { header: 'Dosis', key: 'dosis', width: 14 },
        { header: 'Fecha', key: 'fecha', width: 13, format: 'date' },
        { header: 'Estado', key: 'estado', width: 12 },
      ],
      rows: data.vaccine_reminders.items.map((v) => ({
        paciente: v.patient,
        vacuna: v.vaccine,
        dosis: v.dose,
        fecha: v.reminder_at,
        estado: v.overdue ? 'Atrasada' : 'Próxima',
      })),
    },
    ...(data.probable_deliveries ? [{
      name: 'Fechas probables de parto',
      caption: `${data.probable_deliveries.count} embarazos en seguimiento`,
      columns: [
        { header: 'Paciente', key: 'paciente', width: 30 },
        { header: 'Edad', key: 'edad', width: 8, format: 'int' },
        { header: 'Teléfono', key: 'telefono', width: 14 },
        { header: 'FUM', key: 'fum', width: 13, format: 'date' },
        { header: 'Fecha probable', key: 'fpp', width: 15, format: 'date' },
        { header: 'Semanas', key: 'semanas', width: 10, format: 'int' },
        { header: 'Datación', key: 'datacion', width: 12 },
      ],
      rows: data.probable_deliveries.items.map((p) => ({
        paciente: p.patient,
        edad: p.age,
        telefono: p.phone,
        fum: p.fum,
        fpp: p.edd,
        semanas: p.weeks,
        // Sin esto, una FPP por ecografía y una por FUM se leen igual en la hoja,
        // y no lo son: pueden diferir semanas.
        datacion: p.dating === 'ultrasound' ? 'Ecografía' : p.dating === 'lmp' ? 'FUM' : '—',
      })),
    } satisfies SheetSpec] : []),
  ];
}
