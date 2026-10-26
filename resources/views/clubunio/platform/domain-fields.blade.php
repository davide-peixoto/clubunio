<label>Domain<input name="host" required maxlength="253" value="{{ $domain->host ?? '' }}" @readonly(isset($domain)) placeholder="my.verein.ch"></label>
@foreach(['ownership_verified'=>'Domainbesitz geprüft','dns_verified'=>'DNS geprüft','tls_verified'=>'TLS-Zertifikat geprüft','active'=>'Domain aktiv'] as $field=>$label)
<label class="platform-check"><input type="hidden" name="{{ $field }}" value="0"><input type="checkbox" name="{{ $field }}" value="1" @checked($domain->$field ?? false)>{{ $label }}</label>
@endforeach
<label>Prüfvermerk / Begründung<input name="reason" required minlength="10" maxlength="500"></label><div class="platform-actions"><button class="primary">@svg('heroicon-o-check') Domain speichern</button></div>
