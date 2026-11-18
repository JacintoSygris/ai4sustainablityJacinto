<?php

namespace App\Console\Commands;

use App\Services\DemoCompanyBuilder;
use Illuminate\Console\Command;

class SeedDemoCompanies extends Command
{
    protected $signature = 'i4s:demo:companies {--only=* : Correos a generar (por defecto, todas)} {--reset : Borra y regenera los datos de cuentas que ya los tengan} {--password= : Contraseña de las cuentas demo (o DEMO_COMPANIES_PASSWORD)} {--list : Solo muestra las empresas del catálogo}';

    protected $description = 'Crea cuentas ficticias y recorre los seis pasos del asistente con datos coherentes (data/demo_companies.json)';

    public function handle(DemoCompanyBuilder $builder): int
    {
        $catalogue = DemoCompanyBuilder::catalogue();
        $companies = collect($catalogue['companies']);
        $only = array_map(fn ($email) => mb_strtolower(trim((string) $email)), (array) $this->option('only'));
        if ($only !== []) {
            $companies = $companies->filter(fn (array $company) => in_array(mb_strtolower($company['email']), $only, true));
        }

        if ($this->option('list') || $companies->isEmpty()) {
            $this->table(['Correo', 'Empresa', 'NACE', 'Plantilla'], $companies->map(fn (array $c) => [$c['email'], $c['name'], $c['nace_code'], $c['operations']['employee_count'] ?? ''])->all());

            return $companies->isEmpty() ? self::FAILURE : self::SUCCESS;
        }

        $password = (string) ($this->option('password') ?: env('DEMO_COMPANIES_PASSWORD', ''));
        if ($password === '') {
            $this->error('Indica --password o la variable DEMO_COMPANIES_PASSWORD (mínimo 8 caracteres).');

            return self::FAILURE;
        }

        $summaries = [];
        $failures = 0;
        foreach ($companies as $company) {
            $this->line('');
            $this->info("== {$company['name']} <{$company['email']}>");
            try {
                $summaries[] = $builder->build($company, $catalogue['typed_figures'] ?? [], $password, (bool) $this->option('reset'), fn (string $line) => $this->line("   {$line}"));
            } catch (\Throwable $exception) {
                $failures++;
                $this->error('   ERROR: '.$exception->getMessage());
                if ($this->getOutput()->isVerbose()) {
                    $this->line('   '.$exception->getFile().':'.$exception->getLine());
                    $this->line('   '.mb_substr($exception->getTraceAsString(), 0, 1500));
                }
            }
        }

        if ($summaries !== []) {
            $this->line('');
            $this->table(
                ['Correo', 'Temas IA', 'Aceptados', 'Duda', 'Rechazados', 'Añadidos', 'Confirmados', 'Datos', 'Completados', 'Cifras XBRL', 'No aplican', 'Versión', 'Informe'],
                array_map(fn (array $s) => [$s['email'], $s['ai_topics'], $s['accepted'], $s['unsure'], $s['rejected'], $s['added'], $s['confirmed_topics'], $s['datapoints'], $s['completed'], $s['typed'], $s['not_applicable'], $s['version_approved'] ? 'aprobada' : 'pendiente', ($s['report_status'] ?? '?').($s['report_content_ready'] ? ' (contenido listo)' : '')], $summaries),
            );
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
