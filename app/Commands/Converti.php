<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

use Illuminate\Support\Facades\Storage;

use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\DateTime;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;
use DateTimeImmutable;

class Converti extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'converti {file? : file CSV}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Converte file CSV in vCard per ogni medico';

    /**
     * Variabili interne
     */
	protected $csvData;
	protected $services;
	protected $medici;

    /**
     * Execute the console command.
     * TODO
     * Da estrarre e mettere in un file a parte
     *
     * @return mixed
     */
    private function crea_servizi()
    {
        $this->services = collect([
            ["name" => "Reparto", "start" => "8.00", "end" => "15.26"],
            ["name" => "Covid", "start" => "8.00", "end" => "15.26"],
            ["name" => "Guardia mattina", "start" => "8.00", "end" => "14.00"],
            ["name" => "Guardia pomeriggio", "start" => "14.00", "end" => "20.00"],
            ["name" => "Covid pomeriggio", "start" => "14.00", "end" => "20.00"],
            ["name" => "Notte", "start" => "20.00", "end" => "8.00"],
            ["name" => "Reperibile gg", "start" => "8.00", "end" => "20.00"],
            ["name" => "Reperibile matt", "start" => "8.00", "end" => "14.00"],
            ["name" => "Reperibile pome", "start" => "14.00", "end" => "20.00"],
            ["name" => "Reperibile notte", "start" => "20.00", "end" => "8.00"],
            ["name" => "Bronco", "start" => "8.00", "end" => "15.26"],
            ["name" => "Maggiore", "start" => "8.00", "end" => "15.26"],
            ["name" => "St 50", "start" => "8.00", "end" => "15.26"],
            ["name" => "St 56", "start" => "8.00", "end" => "15.26"],
            ["name" => "Rar", "start" => "15.00", "end" => "16.00"],
            ["name" => "Ip", "start" => "15.00", "end" => "16.00"],
            ["name" => "Osas", "start" => "15.00", "end" => "16.00"],
            ["name" => "Allergologia", "start" => "15.00", "end" => "16.00"],
            ["name" => "Refertazione", "start" => "8.00", "end" => "15.26"],
            ["name" => "Dh", "start" => "8.00", "end" => "15.26"]
        ]);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $filename = $this->argument('file');

        // Controllare se il file csv esiste ed è leggibile
        if( !is_readable( $filename ) ) {
            $this->error("File $filename non leggibile");
            return false;
        }

        // Aprire il file CSV ed inserirlo nella variabile
        $this->csvData = collect(array_map('str_getcsv', file($filename)));

        // Convertire tutte le parole con la prima lettera maiuscola ed il resto minuscolo
        $this->csvData->transform( function( $row ) {
            return collect($row)->map( function ($string) { return ucfirst(strtolower($string)); } )->toArray();
        });

        // Inizializzazione variabile services con i loro orari
        $this->crea_servizi();
        // Estrazione dei servizi, è il nome della colonna nel file CSV
        // successivamente confrontare con i servizi già noti in modo da aggiungerne in caso
        $temp_servizi = collect($this->csvData->first())->unique()->values();
        if(!$temp_servizi->contains($this->services->pluck("name")))
        {
            // TODO Da non chiedere se il servizio è "Data"
            $this->info("Trovato nuovo servizio da aggiungere: " . $temp_servizi->diff($this->services->pluck("name")));
            if(!$this->confirm("Si vuole continuare comunque?"))
                exit;
            // if($this->confirm("Aggiungere il servizio?")){
                // TODO creare funzione per inserire il nuovo servizio con l'orario
                // sarebbe un sistema volatile fin tanto non si crea il database
                // echo "true";
            // }
            $this->info("Procedo con la creazione dei calendari.");
            $this->newLine();
        }

        // Estrazione dei nomi dei medici
        if( empty($this->medici) ) $this->medici = array(); // Inizializza la variabile come array se non già fatto.
        $this->csvData->skip(1)->each( function( $row ) {
            $this->medici = collect($row)->skip(1)
                                        // rimuovendo i valori zero
                                         // ->reject(function($name){ return strpos($name, " "); })
                                         ->reject(function($name){ return empty($name); })
                                         ->concat($this->medici);
        });
        $this->medici = $this->medici->unique()->values();
        $this->info("Trovati questi medici:");
        echo $this->medici->values();
        $this->confirm("procedo?");
        // TODO
        // Fare controllo se ci sono nomi sbagliati o doppi, es. contenenti "/" o nomi simili per mal battitura

        // Estrazione per ogni medico il giorno ed il servizio e stampa il risultato
        // TODO
        // Controllare se il medico è presente contemporaneamente sull stesso servizio
        $this->medici->each( function( $doctor ) {
            $calendar = new Calendar();
            $this->csvData->skip(1)->each( function( $row ) use ( $doctor, $calendar ) {
                $row = collect($row);
                // Ottieni l'id dei servizi per il medico
                $servizi = $row->filter( function( $value, $key ) use ( $doctor ){
                    return $value == $doctor;
                });
                if($servizi->isNotEmpty()){
                    // La data corrisponde al primo valore della riga
                    $date = $row->first();
                    // Valutare se dover mettere un TimeZone
                    // $calendar->addTimeZone(TimeZone::createFromPhpDateTimeZone(new PhpDateTimeZone('Europe/Rome')));
                    $servizi->each( function( $row, $key ) use ( $date, $calendar ) {
                        $service = collect($this->services->firstWhere("name", collect($this->csvData->first())->get($key)));
                        $start = new DateTime(DateTimeImmutable::createFromFormat('d/m/Y H.i', $date . $service->get("start")), false);
                        $endDate = DateTimeImmutable::createFromFormat('d/m/Y H.i', $date . $service->get("end"));
                        if($service->get("end")=='8.00')
                            $endDate = $endDate->modify('+1 day');
                        $end = new DateTime($endDate, false);
                        $occurrence = new TimeSpan($start, $end);
                        $event = (new Event())
                            // Il "Summary" è il nome del servizio
                            ->setSummary($service->get("name"))
                            ->setOccurrence($occurrence)
                        ;
                        $calendar->addEvent($event);
                    });
                };
            });
            //
            // Salvare come file iCal
            //
            $month = DateTimeImmutable::createFromFormat('d/m/Y', $this->csvData->skip(1)->first()[0])->format('M_Y');
            $calendarFactory = new CalendarFactory();
            $filename = '/' . $doctor . '_' . $month . '.ics';
            $file = getcwd() . $filename;
            if( is_readable( $file ) ) {
                if($this->confirm("Sovrascrivere " . $file . " ?")) {
                    if(Storage::put($file, $calendarFactory->createCalendar($calendar))) {
                        $this->info("Sovrascritto calendario " . $file);
                    }
                }
            } else {
                if(Storage::put($file, $calendarFactory->createCalendar($calendar)))
                    $this->info("Creato calendario " . $file);
            }
        });
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
