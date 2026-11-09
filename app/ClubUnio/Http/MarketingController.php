<?php

namespace App\ClubUnio\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class MarketingController
{
    public function inquiry(Request $request, string $locale)
    {
        abort_unless(array_key_exists($locale, config('clubunio-languages.enabled', [])), 404);
        app()->setLocale($locale);
        $target = redirect()->route('clubunio.marketing', ['locale' => $locale], 303)->withFragment('inquiry');
        $kinds = array_combine(['demo', 'quote', 'both'], explode('|', trans('marketing.inquiry_kinds')));
        $validator = Validator::make($request->all(), [
            'kind' => ['required', Rule::in(array_keys($kinds))],
            'club' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
            'members' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'plan' => ['nullable', Rule::in(array_column(config('clubunio-marketing.plans'), 'key'))],
            'interests' => ['nullable', 'array', 'max:7'],
            'interests.*' => ['required', 'string', Rule::in(explode('|', trans('marketing.inquiry_options')))],
            'message' => ['nullable', 'string', 'max:1200'],
        ]);

        if ($validator->fails()) {
            return $target->withErrors(['inquiry' => trans('marketing.inquiry_invalid')]);
        }

        $data = $validator->validated();
        $lines = [trans('marketing.inquiry_kind').': '.$kinds[$data['kind']]];
        foreach (['club', 'name', 'email', 'members', 'message'] as $field) {
            if (isset($data[$field])) {
                $lines[] = trans('marketing.inquiry_'.$field).': '.$data[$field];
            }
        }
        if (!empty($data['interests'])) {
            $lines[] = trans('marketing.inquiry_interests').': '.implode(', ', $data['interests']);
        }
        $subject = trans('marketing.mail_subject');
        if ($data['kind'] !== 'demo' && !empty($data['plan'])) {
            $subject .= ' — '.trans('marketing.'.$data['plan']);
        }

        if (!config('clubunio-marketing.mail_enabled')
            || !config('mail.mailers.clubunio_marketing.host')
            || !filter_var(config('clubunio-marketing.mail_from'), FILTER_VALIDATE_EMAIL)) {
            return $target->withInput($data)->withErrors(['inquiry' => trans('marketing.inquiry_failure')]);
        }

        try {
            $sent = Mail::mailer('clubunio_marketing')->raw(implode("\n", $lines), fn ($message) => $message
                ->from(config('clubunio-marketing.mail_from'))->to(config('clubunio-marketing.contact'))->replyTo($data['email'])->subject($subject));
        } catch (TransportExceptionInterface) {
            $sent = null;
        }

        return $sent
            ? $target->with('marketing_inquiry_sent', true)
            : $target->withInput($data)->withErrors(['inquiry' => trans('marketing.inquiry_failure')]);
    }

    public function privacy(string $locale)
    {
        $languages = config('clubunio-languages.enabled', []);
        abort_unless(array_key_exists($locale, $languages), 404);
        app()->setLocale($locale);

        return response()->view('clubunio.marketing-privacy', [
            'locale' => $locale,
            'languages' => $languages,
            'contact' => config('clubunio-marketing.contact'),
        ])->header('Content-Language', $locale);
    }

    public function __invoke(Request $request, string $locale)
    {
        abort_unless(array_key_exists($locale, config('clubunio-languages.enabled', [])), 404);
        app()->setLocale($locale);

        return response()->view('clubunio.marketing', [
            'locale' => $locale,
            'languages' => config('clubunio-languages.enabled'),
            'contact' => config('clubunio-marketing.contact'),
            'plans' => config('clubunio-marketing.plans'),
        ])->header('Content-Language', $locale);
    }
}
