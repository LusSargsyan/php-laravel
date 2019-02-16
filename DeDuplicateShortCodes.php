<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Remindly\Layers\Data\Shortcode\ShortcodeModel;
use Remindly\Layers\Data\Event\EventModel;
use Remindly\Layers\Data\PublisherPending\PublisherPendingModel;
use Remindly\Layers\Data\EndUser\EndUserEventSubscription;
use Remindly\Layers\Service\RandomStringGenerator\RandomStringGeneratorService;
use Remindly\Layers\Service\Shortcode\ShortcodeProvider;

class DeDuplicateShortcodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'de-duplicate:shortcodes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'De-duplicate shortcodes';

    protected $shortcodeGenerator;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->shortcodeGenerator = new ShortcodeProvider(new RandomStringGeneratorService());
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $duplicates = ShortCodeModel::groupBy('shortcode')
            ->havingRaw('count(*) > 1')
            ->get([
                \DB::raw("array_to_string(array_agg(event_id),',') AS event_ids"),
                \DB::raw("array_to_string(array_agg(id),',') AS shortcode_ids")
            ]);

        if(!$duplicates->count())
            return true;

        $event_ids = '';
        $shortcode_ids = '';
        $duplicates->each(function ($item, $key) use (&$event_ids,&$shortcode_ids) {
            $event_ids .= ','.$item["event_ids"];
            $shortcode_ids .= ','.$item["shortcode_ids"];
        });

        $event_ids = explode(',',$event_ids);
        $shortcode_ids = explode(',',$shortcode_ids);

        unset($event_ids[0]);
        unset($shortcode_ids[0]);

        $this->updateEventsWithDuplicateShortcodes($event_ids);
        $this->deleteDuplicateShortcodes($shortcode_ids);
    }

    /**
     * @param EventModel $event
     */
    private function generateShortcodeAndAssociate(EventModel $event)
    {
        $code = $this->checkAndGenerateShortcode();
        $code->is_active = true;

        try {
            $code->save();
            $code->event()->associate($event);
            $code->save();
            return $code->shortcode;
        } catch (QueryException $ex) {
            \Log::error($ex->getMessage());
        }
    }

    /**
     * @return ShortcodeModel
     */
    private function checkAndGenerateShortcode()
    {
        return $this->shortcodeGenerator->generate();
    }

    private function deleteDuplicateShortcodes($ids)
    {
        ShortCodeModel::whereIn('id',$ids)
            ->delete();
    }

    private function updateEventsWithDuplicateShortcodes($event_ids)
    {
        $eventWithDuplicateShortcodes = EventModel::whereIn('id',$event_ids)
            ->get();


        $callback = function($event){
            $shortcode = $this->generateShortcodeAndAssociate($event);
            $subsriptions = $event->subscriptions()
                ->update(['referer' => $shortcode]);
        };

        $eventWithDuplicateShortcodes->each($callback);
    }

}
