<label>{{ __('Domain') }}<input name="host" required maxlength="253" value="{{ $domain->host ?? '' }}" @readonly(isset($domain)) placeholder="{{ __('my.verein.ch') }}"></label>
@foreach(['ownership_verified'=>__('Domainbesitz geprüft'),'dns_verified'=>__('DNS geprüft'),'tls_verified'=>__('TLS-Zertifikat geprüft'),'active'=>__('Domain aktiv')] as $field=>$label)
<label class="platform-check"><input type="hidden" name="{{ $field }}" value="0"><input type="checkbox" name="{{ $field }}" value="1" @checked($domain->$field ?? false)>{{ $label }}</label>
@endforeach
<label>{{ __('Prüfvermerk / Begründung') }}<input name="reason" required minlength="10" maxlength="500"></label><div class="platform-actions"><button class="primary">@svg('heroicon-o-check') {{ __('Domain speichern') }}</button></div>
