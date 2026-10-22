# Değişiklik günlüğü

> **Ad değişikliği:** Paket VitalWatch adıyla yeniden adlandırıldı — Composer paketi
> `cestnytr/vitalwatch-laravel`, namespace `CestnyTR\VitalWatch`, yapılandırma
> `config/vitalwatch.php`, komut `vitalwatch:status`, ortam değişkenleri `VITALWATCH_*`,
> imza başlıkları `X-VitalWatch-Signature` / `X-VitalWatch-Timestamp`.
> Aşağıdaki geçmiş kayıtlar yeni adlarla yazılmıştır.

Semver. Hub'ın toplama ucu sözleşmesi geriye uyumlu tutulur: yeni alanlar
eklenebilir, mevcut alanlar kaldırılmaz. Eski paket sürümleri çalışmaya
devam eder.

```bash
composer require cestnytr/vitalwatch-laravel
```

---

## 0.2.5

### Değişti

- **Düz CamelCase adlar artık `[token]` olmuyor.** Uzun dizi maskesi
  (24+ karakter) `InvalidOperationException`,
  `HandleNonSuccessAndDebuggerNotification` gibi sınıf ve metot adlarını da
  jeton sanıyor, yığın izleri okunmaz oluyordu. Rakam, `-`/`_` taşımayan ve
  `^[A-Za-z][a-z]+(?:[A-Z][a-z]+)+$` desenine tamamen uyan aday jeton
  sayılmıyor. Gerçek jeton ve oturum kimlikleri neredeyse hep rakam taşır;
  rakamlı, ardışık büyük harfli ya da tümü büyük harf diziler eskisi gibi
  maskelenir. Kural hub ve öbür SDK'larla birebir aynı.
- **Parmak izi yan etkisi:** böyle bir ad içeren hata mesajları yükseltmeden
  sonra bir kez yeni parmak izi altında toplanır; panelde yeni satır açılır
  ve bir kez "yeni hata" uyarısı gelebilir.

---

## 0.2.4

### Eklendi

- **Zaman aşımına uğrayan kuyruk işi raporlanıyor** (`JobTimedOut`).
  Deneme hakkı bitmişse Laravel işi başarısız sayıyor ve `JobFailed`
  geliyordu; hak kaldığında ise işçi süreci sinyalle öldürülüyor ve
  panele hiçbir iz düşmüyordu. Oysa zaman aşımına uğrayan iş, tekrar
  denenip başarılı olsa bile arıza işareti: kuyruk birikiyor ve süreç her
  turda ölüyor.

  Laravel önce işi başarısız işaretleyip sonra olayı yaydığı için iki kayıt
  riski vardı; iş başına işaret tutuluyor, tek kayıt açılıyor. Stack yok:
  istisna üretilmiyor.

---

## 0.2.3

### Eklendi

- **Sürüm etiketi otomatik.** `VITALWATCH_RELEASE` boşsa sırayla CI/PaaS
  değişkenleri (`GIT_COMMIT`, `GIT_SHA`, `COMMIT_SHA`, `SOURCE_VERSION`,
  `VERCEL_GIT_COMMIT_SHA`, `RENDER_GIT_COMMIT`, `HEROKU_SLUG_COMMIT`,
  `CI_COMMIT_SHA`), sonra proje kökündeki `.git` okunuyor (dal, packed-refs,
  ayrık HEAD, `gitdir:` dosyası). Commit ilk 12 haneye kısaltılıyor. Kabuk
  komutu yok, önbellek yok (FPM işçisi deploy'dan sağ çıkıyor). Hub artık
  hatanın hangi deploy'la başladığını gösterebiliyor. Kural üç pakette aynı.
- **`vitalwatch:status` sürüm etiketini ve kaynağını gösteriyor**
  (`kaynak: VITALWATCH_RELEASE | <DEĞİŞKEN> | .git | yok`).
- **`vitalwatch:status` güncelleme denetimi.** Packagist'teki en yeni kararlı
  sürüm yazılıyor; kurulu olan eskiyse `composer require
  cestnytr/vitalwatch-laravel:^X.Y` önerisi çıkıyor (uyarı, çıkış kodu değişmez).
  Ağ yoksa `denetlenemedi`; `VITALWATCH_STATUS_OFFLINE=1` atlar.
- **Ortam adı normalleştiriliyor.** Hub yalnızca production/staging/local
  kabul ediyor ve küme dışını — canlılık dahil — 204 dönüp atıyor.
  `APP_ENV=development` ya da `prod` ile kurulan proje hiçbir şey
  göndermiyormuş gibi görünüyordu. Takma adlar çevriliyor (`prod`/`live`,
  `stage`/`stg`/`preprod`/`uat`, `dev`/`development`/`test`/`testing`);
  tanınmayan değer olduğu gibi gidiyor ve teşhis komutu hata veriyor.
  Tablo üç pakette aynı.
- **Hata gönderimi yanıtı bekletmiyor.** Ölçülen HTTP isteğinde hata ve
  yavaş sorgu olayı terminate'e bekletiliyor; eskiden `report()` yanıttan
  önce hub'ı zaman aşımı kadar bekleyebiliyordu. terminate'e ulaşmayan
  süreçte kapanışta gönderiliyor.
- **`job_failed`.** README yıllardır yazıyordu ama hiç gönderilmiyordu.
  Deneme hakkı biten iş `JobFailed` olayıyla kaydediliyor; Laravel'in
  ardından handler'a verdiği aynı hata elenir.
- **`command_failed`.** Başarısız zamanlanmış görev (sıfır olmayan çıkış
  dahil). Cron'daki sessiz başarısızlık görünür oldu. PHP yolu görev
  adından ve mesajdan atılıyor (`artisan rapor:gonder`); yol sunucudan
  sunucuya değişip aynı görevi iki satıra bölmesin. `runInBackground()`
  görevlerinde Laravel başarısızlık olayı üretmiyor — bkz. README.
- **Kuyruk ve zamanlayıcıda canlılık.** Yalnızca kuyruk ya da zamanlayıcı
  çalıştıran uygulama HTTP isteği görmediği için canlılık isteği hiç gitmiyor, kurulum
  "sessiz" görünüyordu.
- **Octane.** İşçiler CLI altında çalıştığı için istek ölçümü hiç
  kurulmuyordu. Kuruluyor; `LARAVEL_START` yerine istek anı kullanılıyor
  (yoksa her istek işçinin ömrü kadar "yavaş" görünürdü). Sayaçlar istek
  sonunda ve kuyruk işi başlarken sıfırlanıyor. Recorder açılışta
  çözülüyor: Octane'ın istek başına açtığı uygulama kopyaları tek örneği
  paylaşıyor (yoksa kancalar ile ölçüm ayrı örneklere yazıyor, istek
  kayıtlarında sorgu sayısı hep 0 görünürdü).

### Düzeltildi

- **`vitalwatch:status` hizası.** Etiketler bayta göre dolduruluyordu; Türkçe
  harfli satırlar (`Paket sürümü`, `Proje anahtarı`) kayıyordu.
- **Tek arıza iki kayıt oluyordu.** Hata veren istek stack'li `exception`'ın
  yanında ölçüm middleware'inden stack'siz bir `http_5xx` daha açıyordu.
  İstek işaretleniyor; işaretli istekte `http_5xx` açılmıyor.
- **Hata kayıtlarında rota ve yöntem yoktu.** Panelde hatanın hangi uçta
  patladığı görünmüyor, "Yol" `/` kalıyordu. Artık desen gönderiliyor
  (`/api/urunler/{id}`).
- **Uzun ömürlü süreçte yeni hatalar yutulabiliyordu.** Tekrar eleme
  `spl_object_id` dizisiydi; kimlik çöp toplanınca yeniden kullanılıyor,
  dizi hiç temizlenmiyordu. Kuyruk işçisinde aynı kimliği alan yeni hata
  "zaten raporlandı" sayılıyordu. `WeakMap`'e geçildi.
- **Günlüğe yazılan 4xx hub'a gidiyordu.** Paket log olayını da dinliyor;
  uygulama yakaladığı bir 404'ü `Log::warning(..., ['exception' => $e])` ile
  yazınca Laravel'in dontReport listesi atlanıyordu. 400–499 taşıyan
  `HttpException` ve `ModelNotFoundException` artık raporlanmıyor.
- **`VITALWATCH_TIMEOUT` birimi.** `(int)` okunuyordu: `0.5` sıfıra — curl'de
  sınırsız beklemeye — dönüyordu. Node paketi aynı adla milisaniye
  beklediği için `2000` yazılınca PHP süreci 2000 saniye bekleyebilirdi.
  Artık saniye, 100 ve üstü milisaniye; geçersiz, sıfır, negatif ya da
  sonsuz değer 2 sn; sonuç 0,1–10 sn aralığına kırpılıyor (kural bütün
  SDK'larda aynı). `_MS` curl seçenekleri ve `CURLOPT_NOSIGNAL`
  kullanılıyor.
- **8 KB sınırı.** Hub büyük gövdeyi okumadan atıp yine 204 dönüyor. Alanlar
  karakterle kesildiği için 3–4 baytlık karakter taşıyan stack sınırı
  aşabiliyordu. Önce stack, sonra mesaj, sonra en yavaş sorgu atılıyor.
  Geçersiz UTF-8 artık gövdeyi düşürmüyor (`JSON_INVALID_UTF8_SUBSTITUTE`).
- **Geçersiz UTF-8 taşıyan metinde e-posta maskelenmiyordu.** E-posta deseni
  `/u` bayraklı; bozuk baytta desen sessizce atlanıyor, adres olduğu gibi
  gidiyordu (ör. latin1 veritabanı hatası). Metin önce `mb_scrub` ile
  onarılıyor.
- **Senkron kuyruk isteğin ölçümünü siliyordu.** `sync`/`deferred`
  bağlantısındaki iş isteğin içinde çalışıyor; iş başlarken yapılan
  sıfırlama isteğin başlangıç zamanını silip `slow_request` ve `http_5xx`'i
  kaybettiriyordu, canlılık da yanıttan önce ağ isteği atabiliyordu. Bu
  işlerde sıfırlama ve canlılık atlanıyor; `job_failed` kaydı kalıyor.
- **Uzun iş sınıfı adları `[token]` oluyordu.** `job_failed` rotasındaki
  sınıf adı artık maskelenmiyor (kişisel veri değil); farklı işler panelde
  tek satırda birleşiyordu. Zamanlanmış görev adı maskelenmeye devam ediyor.
- **Octane'da rotadan önce düşen hata** isteği işaretlemiyordu; aynı arıza
  ayrıca `http_5xx` açıyordu.
- **Maskeleme okunur adları bozuyordu.** Yükleme dosya adları okunur kalıyor. `<ad>-<zaman damgası>-<rastgele>.jpeg`
  biçimindeki adlar panelde `/uploads/discount/[token][card]-752066249.jpeg`
  diye çıkıyordu: zaman damgası kart, uzun ad jeton sanılıyordu.

  - **Kart:** yalnızca 2–9 ile başlayan ve Luhn'dan geçen dizi maskelenir.
    Kart ağlarının hiçbiri 0/1 ile başlamıyor, milisaniye damgası 1 ile
    başlıyor. Bilinen taviz: Luhn'u tutmayan (yanlış yazılmış) kart
    numarası ve 1 ile başlayan UATP kartları artık maskelenmiyor.
  - **Jeton:** 16+ karakterlik bölünmemiş parça taşıyan ya da harf içeren hex
    dizi (UUID, hash) maskelenir. Tireyle birleşmiş kısa parçalar okunur addır.
    Bilinen taviz: tire/alt çizgiyle kısa parçalara bölünmüş rastgele
    anahtarlar (ör. base64url jetonların bir kısmı) kaçabilir. İki taviz
    de dosya adlarının okunur kalması için bilerek kabul edildi.
  - **Telefon:** önünde rakam varsa eşleşmez. Son on hanesi 5 ile başlayan
    zaman damgası `179[phone]` oluyordu.

  Kurallar hub, `t.js`, Node, Laravel ve Python'da birebir aynı; 28 ortak
  vektörle karşılaştırıldı.

---

## 0.2.2

### Eklendi

- **`vitalwatch:status --vitalwatch`.** Yalnızca canlılık isteği gönderir, panele hata
  düşürmez. Onlarca kurulumu gezen güncelleme döngüsü için: `--test` orada
  panele onlarca sahte hata bırakıyordu.

`v0.2.1` etiketi `v0.2.0` ile aynı commit'i gösteriyor; ayrı bir sürüm değil.

---

## 0.2.0

**Hub gereksinimi — bu sürümde önemli.** Ölümcül hatalar `fatal` türüyle
gönderiliyor ve hub'ın izinli tür listesinde bu tür bulunmalı. Hub
güncellenmeden yükseltilirse **ölümcül hatalar sessizce düşer**: toplama ucu
geçersiz olaya da 204 döner, hiçbir yerde iz kalmaz. Önce hub deploy edilmeli.

Canlılık nabzı için de canlılık kolonlarının göçü gerekir; o eksikse canlılık isteği
zarar vermez, yalnızca kaydedilmez.

### Eklendi

- **Ölümcül hata yakalama.** Bellek tükenmesi, zaman aşımı ve derleme
  hataları PHP'de exception üretmez: süreç ölür, exception handler hiç
  çalışmaz. Siteyi **gerçekten düşüren** hata sınıfı bugüne kadar panele hiç
  düşmedi — uptime probu 500'ü görüyordu ama sebebini kimse bilmiyordu.

  `register_shutdown_function` ile yakalanıyor. `exception` değil `fatal`
  türüyle gönderiliyor: biri uygulamanın yakalayabildiği bir durum, diğeri
  sunucunun sınırına çarpması, ve bu sınıfı ayrıca süzebilmek gerekiyor.

  Açılışta 256 KB tampon ayrılıp shutdown anında serbest bırakılıyor. Bellek
  tükendiğinde raporlama kodunun kendisi de yer bulamaz; tampon olmadan kanca
  yazılır ama tam ihtiyaç anında sessizce çalışmaz.

  Yalnızca ölümcül türler gönderilir (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`,
  `E_COMPILE_ERROR`, `E_USER_ERROR`). Uyarı ve bildirimler her istekte
  onlarca üretilebilir ve paneli boğardı.

- **Canlılık nabzı.** Sekiz saatte bir olay taşımayan bir istek gönderilir.
  Hub bir kurulumun çalıştığını yalnızca gelen hatalardan anlıyordu; hatasız
  çalışan uygulama "kurulum bozuk" görünüyordu.

  Laravel zamanlayıcısına **bağlanmadı**: paket onlarca projeye kuruluyor ve
  hepsinde çalışan bir cron olduğu varsayılamaz. `terminate()` aşamasında,
  yanıt gönderildikten sonra atılır; süresi gelmemişse yalnızca bir önbellek
  okuması yapılır.

  Yan fayda: hub'ın kendi uptime probu da bir istektir, yani hiç ziyaretçisi
  olmayan site bile nabzını atmaya devam eder.

### Düzeltildi

- `Recorder`'ın yapılandırmasında proje anahtarı yoktu. Canlılık önbelleği
  bu anahtarla ayrıldığı için, aynı cache deposunu paylaşan iki uygulama
  birbirinin nabzını bastırırdı.

---

## 0.1.2

- Teşhis komutu gerçek gönderim sonucunu okuyor.

## 0.1.1

- Exception kancası kurulum sırasına bağlı olarak kaçırılabiliyordu.

## 0.1.0

- İlk sürüm.
