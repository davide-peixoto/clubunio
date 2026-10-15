<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Models\TrainingOption;
use App\ClubUnio\Services\PublicWebService;
use App\ClubUnio\Services\RegistrationDataService;
use App\ClubUnio\Services\RegistrationDocumentService;
use App\ClubUnio\Services\SignageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicWebController
{
    public function signage(Request $request, Tenant $tenant)
    {
        app(PublicWebService::class)->authorize($tenant, 'signage');
        $periods = TrainingOption::where('tenant_id', $tenant->id)->where('category', 'period')->where('active', true)->orderBy('sort_order')->get();
        $periodId = $request->query('period_id', $periods->count() === 1 ? $periods->first()->id : null);
        $data = app(SignageService::class)->publicDisplay($tenant, ['period_id' => $periodId]);

        return response()->view('clubunio.public.signage', $data + compact('tenant', 'periods', 'periodId'))->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function registration(Request $request, Tenant $tenant)
    {
        app(PublicWebService::class)->authorize($tenant, 'registration');
        $key = 'public-registration.'.$tenant->id;
        $form = $request->session()->get($key);
        if ($request->boolean('new') && $form && DB::connection('clubunio')->table('clubora.public_registration_submissions')->where('tenant_id', $tenant->id)->where('request_id', $form['id'])->exists()) {
            $form = null;
        }
        if (! $form || $form['at'] < time() - 3600) {
            $form = ['id' => (string) Str::uuid(), 'at' => time()];
            $request->session()->put($key, $form);
        }
        $choices = [];
        foreach (['country_id' => 'countries', 'nationality_id' => 'countries', 'gender_id' => 'genders', 'billing_recipient_id' => 'billing_recipients'] as $field => $table) {
            $choices[$field] = DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id)->where('active', true)->orderBy('name')->pluck('name', 'id');
        }

        return response()->view('clubunio.public.registration', compact('tenant', 'form', 'choices'))->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function submit(Request $request, Tenant $tenant)
    {
        app(PublicWebService::class)->authorize($tenant, 'registration');
        $request->validate(['request_id' => 'required|uuid']);
        $form = $request->session()->get('public-registration.'.$tenant->id);
        abort_unless($form && hash_equals($form['id'], (string) $request->input('request_id')) && $form['at'] >= time() - 3600, 419);
        $request->validate(['consent' => 'accepted', 'website' => 'prohibited', 'portrait' => 'nullable|file|max:5120|mimetypes:image/jpeg,image/png,image/webp',
            'identity' => 'nullable|array|max:3', 'identity.*' => 'file|max:10240|mimetypes:application/pdf,image/jpeg,image/png,image/webp']);
        if ($form['at'] > time() - 3) {
            throw ValidationException::withMessages(['first_name' => 'Bitte Eingaben prüfen und erneut absenden.']);
        }
        $allowed = [...RegistrationDataService::CORE, ...RegistrationDataService::DETAILS, '_token', 'request_id', 'consent', 'website', 'portrait', 'identity'];
        abort_if(array_diff(array_keys($request->all()), $allowed), 422);
        try {
            DB::connection('clubunio')->transaction(function () use ($request, $tenant, $form) {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                if (DB::connection('clubunio')->table('clubora.public_registration_submissions')->where('tenant_id', $tenant->id)->where('request_id', $form['id'])->exists()) {
                    return;
                }
                $r = app(RegistrationDataService::class)->submitPublic($tenant, $request->only([...RegistrationDataService::CORE, ...RegistrationDataService::DETAILS]), $form['id']);
                $documents = app(RegistrationDocumentService::class);
                if ($request->hasFile('portrait')) {
                    $documents->addPublic($tenant, $r, $form['id'], 'portrait', $request->file('portrait'));
                }
                foreach ($request->file('identity', []) as $file) {
                    $documents->addPublic($tenant, $r, $form['id'], 'identity', $file);
                }
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $error) {
            $reference = (string) Str::uuid();
            Log::error('clubunio.public_registration.failed', ['tenant_id' => $tenant->id, 'reference' => $reference,
                'exception_class' => $error::class, 'code' => (string) $error->getCode(), 'origin' => basename($error->getFile()).':'.$error->getLine()]);
            throw ValidationException::withMessages(['first_name' => 'Anmeldung nicht gespeichert. Bitte erneut versuchen. Referenz: '.$reference]);
        }

        return redirect()->route('clubunio.public.registration', ['tenant' => $tenant->slug])->with('submitted', true);
    }
}
