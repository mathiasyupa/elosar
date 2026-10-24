import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8');

describe('responsive workspace contract', () => {
  const css = source('src/index.css');

  it('keeps parallel agent worktrees outside the main test run', () => {
    expect(source('vite.config.ts')).toContain("'**/.worktrees/**'");
  });

  it('sizes layouts from the usable content area and contains horizontal overflow', () => {
    expect(css).toContain('container-name: workspace');
    expect(css).toContain('@container workspace');
    expect(css).toMatch(/\.content\s*\{[^}]*overflow-x:\s*hidden/s);
    expect(css).toMatch(/\.table-scroll\s*\{[^}]*overflow-x:\s*auto/s);
  });

  it('gives each dense product surface a semantic responsive hook', () => {
    expect(source('src/features/invoices/InvoicesPage.tsx')).toContain('invoice-workspace');
    expect(source('src/features/services/ServicesPage.tsx')).toContain('services-workspace');
    expect(source('src/features/clinic/TeamPage.tsx')).toContain('team-table-region');
    expect(source('src/features/reports/SettlementPanel.tsx')).toContain('report-kpi-grid');
    expect(source('src/features/reports/SettlementPanel.tsx')).toContain('report-analysis-grid');
    expect(source('src/features/reports/ProfitLossPanel.tsx')).toContain('report-analysis-grid');
    expect(source('src/features/patients/workspace/PatientSummaryTab.tsx')).toContain('patient-summary-grid');
    // El carnet perinatal son ~19 filas con dos campos editables cada una: es la
    // superficie más densa del expediente.
    expect(source('src/features/patients/workspace/PatientGynecoTab.tsx')).toContain('gyneco-workspace');
  });

  it('keeps dense tables inside local scroll regions', () => {
    expect(source('src/features/invoices/InvoicesPage.tsx')).toContain('table-scroll');
    expect(source('src/features/services/ServicesPage.tsx')).toContain('table-scroll');
    expect(source('src/features/clinic/TeamPage.tsx')).toContain('table-scroll');
    expect(source('src/features/obstetrics/PregnancyCard.tsx')).toContain('table-scroll');
    expect(source('src/features/patients/ClinicalWorklists.tsx')).toContain('table-scroll');
  });

  it('keeps service labels and workspace selection accessible', () => {
    const services = source('src/features/services/ServicesPage.tsx');
    expect(services).toContain('htmlFor="service-name"');
    expect(services).toContain('id="service-name"');
    expect(services).toContain('htmlFor="service-category"');
    expect(services).toContain('htmlFor="service-price"');

    expect(source('src/features/clinics/ClinicsPage.tsx')).toContain('aria-pressed={isActive}');
  });
});
