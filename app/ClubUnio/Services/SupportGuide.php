<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Http;

final class SupportGuide
{
    public const TOPICS=['login','expense','profile','tenants','chat','translation','planning','teams','members','membership','wallet','events','reservations','documents','keyboxes','notifications','push','diagnostics'];

    public function faq(string $locale,?string $search=null): array
    {
        $locale=in_array($locale,['de','en'],true)?$locale:'de';
        $articles=trans('support.faq',locale:$locale);
        if(!is_array($articles))return [];
        $articles=array_intersect_key($articles,array_fill_keys(self::TOPICS,true));
        $search=trim($search??'');
        return $search===''?$articles:array_filter($articles,fn($item)=>mb_stripos($item['title'],$search)!==false||mb_stripos($item['body'],$search)!==false);
    }

    public function topic(string $text): ?string
    {
        return match (true) {
            (bool) preg_match('/übersetz|uebersetz|translat|zielsprache|target.language/iu',$text) => 'translation',
            (bool) preg_match('/spesen|expense|receipt/i', $text) => 'expense',
            (bool) preg_match('/schlüsselbox|schluesselbox|keybox|igloo|garderobe|ballhaus|equipment.room/iu',$text) => 'keyboxes',
            (bool) preg_match('/wallet|mitgliederausweis|member.card|rabatt|discount/iu',$text) => 'wallet',
            (bool) preg_match('/check.in|\bevents?\b|veranstaltung|generalversammlung|general.assembly/iu',$text) => 'events',
            (bool) preg_match('/mitglied.*anmeld|neue.mitgliedschaft|weiteres.kind|neues.kind|\bmembership\b|another.child|new.child|register.*child|add.*child/iu',$text) => 'membership',
            (bool) preg_match('/bus|reservier|reservation|platzanfrag|platzbelegung|pitch.request|pitch.occupancy/iu',$text) => 'reservations',
            (bool) preg_match('/teamplan|teilnahme|kalender|termin|participat|calendar|team.planning/iu',$text) => 'planning',
            (bool) preg_match('/\bteams?\b|teamdetail|mannschaft|probetrain|trial.training|staff|trainingszeit/iu',$text) => 'teams',
            (bool) preg_match('/eltern|kinder|volljähr|volljaehr|parent|children|\bchild\b|adult.consent|mitglied|\bmembers?\b/iu',$text) => 'members',
            (bool) preg_match('/mandant|verein.wechsel|club.build|custom.build|switch.*club|tenant/iu',$text) => 'tenants',
            (bool) preg_match('/push|fokusmodus|focus.mode/iu',$text) => 'push',
            (bool) preg_match('/benachrichtig|posteingang|glocke|notification|inbox|mark.all.read/iu',$text) => 'notifications',
            (bool) preg_match('/dokument|document|rechnung|invoice|pdf/iu',$text) => 'documents',
            (bool) preg_match('/chat|nachricht|message|reaktion|reaction|emoji|wisch|swipe|camera|kamera/iu',$text) => 'chat',
            (bool) preg_match('/face.?id|touch.?id|biometr/iu',$text) => 'login',
            (bool) preg_match('/passkey|authenticator|login|anmeld(en|ung)|sign.in/i', $text) => 'login',
            (bool) preg_match('/profil|profile|app.sprache|app.language|gerät|geraet|device/iu',$text) => 'profile',
            (bool) preg_match('/diagnos|support|hilfe|help/iu',$text) => 'diagnostics',
            default => null,
        };
    }

    public function answer(string $question, string $locale, ?string $previousTopic = null): array
    {
        $locale = in_array($locale, ['de', 'en'], true) ? $locale : 'de';
        $topic = $this->topic($question);
        // Only use conversation context for short, generic follow-ups.
        if ($topic === null && in_array($previousTopic, self::TOPICS, true)
            && preg_match('/^(?:and then|what next|how exactly|what now|und dann|wie weiter|wie genau|und jetzt)[?!. ]*$/iu', trim($question))) {
            $topic = $previousTopic;
        }
        return $this->answerForTopic($topic,$locale);
    }

    public function answerForTopic(?string $topic,string $locale): array
    {
        $locale=in_array($locale,['de','en'],true)?$locale:'de';
        if(!in_array($topic,self::TOPICS,true))return ['text'=>trans('support.handoff',locale:$locale),'source'=>null,'ai'=>false];
        $article=$this->faq($locale)[$topic]??null;
        if(!is_array($article))return ['text'=>trans('support.handoff',locale:$locale),'source'=>null,'ai'=>false];
        $fallback = ['text' => $article['body'], 'source' => $topic, 'ai' => false];
        // Expanded known help is already concise. Preserve existing legacy AI behavior,
        // but add no provider calls or costs for the new curated topics.
        if(!in_array($topic,['login','expense'],true))return $fallback;
        $config = config('clubunio-support');
        if (!$config['openai_key'] || !$config['openai_project'] || !OpenAiModels::supports($config['model'])) return $fallback;
        try {
            // Only curated public text and an allowlisted topic leave ClubUnio.
            $result = Http::withToken($config['openai_key'])->withHeaders(['OpenAI-Project' => $config['openai_project']])
                ->withoutRedirecting()->connectTimeout(3)->timeout(10)->post('https://api.openai.com/v1/responses', [
                    'model' => $config['model'], 'store' => false, 'max_output_tokens' => 300,
                    'reasoning' => OpenAiModels::reasoning($config['model']),
                    'instructions' => 'Summarize the provided public support article in its own language. Do not invent steps or request personal data. You cannot take any action.',
                    'input' => $article['title']."\n".$article['body'],
                ]);
            if ($result->successful() && $result->json('status', 'completed') === 'completed') {
                foreach ((array) $result->json('output') as $item) {
                    foreach ($item['content'] ?? [] as $part) {
                        if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)
                            && trim($part['text']) !== '' && mb_strlen($part['text']) <= 2500) {
                            return ['text' => $part['text'], 'source' => $topic, 'ai' => true];
                        }
                    }
                }
            }
        } catch (\Illuminate\Http\Client\ConnectionException) {
            // The public article remains available when the model cannot be reached.
        }
        return $fallback;
    }
}
