# cestnytr/vitalwatch-laravel

Laravel uygulamaları için VitalWatch izleme istemcisi. Exception'ları, yavaş istekleri ve yavaş
SQL sorgularını **kişisel veri toplamadan** merkezî VitalWatch Hub'a raporlar.

> Bu paket izlenen projelere kurulur. Hub'ın kendisi ayrı bir repodadır:
> [vitalwatch-hub](https://github.com/CestnyTR/vitalwatch-hub)

| | |
|---|---|
| **Paket** | `cestnytr/vitalwatch-laravel` |
| **Destek** | Laravel 11 · 12 · 13 · PHP 8.2+ |
| **Lisans** | MIT |
| **Durum** | 🚧 Geliştirme aşamasında (Faz 2) |

---

## Kurulum

```bash
composer require cestnytr/vitalwatch-laravel
php artisan vendor:publish --tag=vitalwatch
```

`.env` dosyasına:

```env
VITALWATCH_ENABLED=true
VITALWATCH_URL=https://vitalwatch.example.com
VITALWATCH_KEY=proje-anahtari
VITALWATCH_SECRET=...
VITALWATCH_ENV=production
```

Service provider Laravel'in paket keşfi ile otomatik yüklenir; elle kayıt gerekmez.
Anahtar ve secret değerleri VitalWatch panelindeki **Kurulum** sekmesinden alınır.

### Kurulumu doğrulayın

```bash
php artisan vitalwatch:status
```

Bu adım atlanmamalı. Paketin en pahalı arıza biçimi sessiz çalışmamadır: secret eksik
kopyalanmışsa hiçbir şey patlamaz, hiçbir log düşmez — hub geçersiz imzaya da `204`
döner. Kurulum aylarca çalışmıyor olabilir ve bu sessizlik "sorun yok" sanılır.

Komut yerelde yanlış olan ne varsa gösterir: eksik değişken, hatalı secret uzunluğu,
şifresiz hub adresi. Ayrıca (0.2.3+):

- **Sürüm etiketi** ve nereden bulunduğu: `Sürüm etiketi: 3f2a1b4c5d6e (kaynak: .git)`.
  Kaynak `VITALWATCH_RELEASE`, ortam değişkeninin adı (`GIT_COMMIT` …), `.git` ya da `yok`.
- **Güncel sürüm** — Packagist'teki en yeni kararlı sürüm. Kurulu olan eskiyse
  `Güncelleme var: composer require cestnytr/vitalwatch-laravel:^X.Y` uyarısı çıkar; bu bir hata
  sayılmaz, çıkış kodu değişmez. Packagist'e ulaşılamazsa `denetlenemedi` yazar. Ağı
  olmayan sunucuda ya da CI'da `VITALWATCH_STATUS_OFFLINE=1` denetimi atlar. Bu istek
  yalnızca komutta atılır; uygulama çalışırken paket dışarıya başka istek atmaz.

```bash
php artisan vitalwatch:status --test         # hub'a bir sınama olayı gönderir — panelde hata olarak görünür
php artisan vitalwatch:status --vitalwatch   # yalnızca canlılık isteği — panele hata düşürmez
```

`--test` tek bir kurulumu doğrularken doğru seçim. Onlarca kurulumu gezen bir güncelleme
döngüsünde `--vitalwatch` kullanılır: `--test` orada panele onlarca sahte hata bırakır.

Verinin gerçekten ulaştığı yalnızca **hub panelinden** doğrulanır: proje satırındaki
bağlantı durumu `Bağlı` görünmelidir. Komut bunu kendi başına söyleyemez, çünkü hub
geçerli ile geçersiz imzayı dışarıya aynı yanıtla karşılar.

---

## Ne toplar

| Alan | Örnek |
|---|---|
| `kind` | `exception` · **`fatal`** · `http_5xx` · `slow_request` · `slow_query` · `job_failed` · `command_failed` |
| `msg`, `exception_class`, `stack`, `file`, `line` | Hata bilgisi (maskelemeden geçer) |
| `route` | **Desen, gerçek id değil.** İstek kayıtlarında (`slow_request`, `http_5xx`) yöntemle birlikte: `GET /api/products/{id}`. Hata kayıtlarında (`exception`, 0.2.3+) yalnızca yol — `/api/products/{id}` — ve yöntem ayrı `method` alanında. `job_failed`'da işin sınıfı, `command_failed`'da görev adı |
| `controller`, `method`, `status` | `ProductController@show`, `GET`, `500` |
| `duration_ms`, `memory_mb` | Performans |
| `query_count` | SQL sorgu sayısı — N+1 tespiti |
| `slowest_query_sql` | **Normalize:** `select * from users where email = ?` |
| `release`, `php_version`, `framework_version` | Ortam |

### Kuyruk, zamanlayıcı ve Octane (0.2.3+)

| Durum | Ne gelir |
|---|---|
| Deneme hakkı biten kuyruk işi | `job_failed` — "Yol" sütununda işin sınıfı. Laravel aynı hatayı ardından handler'a da veriyor; tek kayıt kalır |
| Ara denemede düşen iş | `exception` (her deneme) |
| Zaman aşımına uğrayan iş | `job_failed` — mesajda iş ve süre, stack yok (süreç sinyalle ölüyor). Hakkı bitmişse yukarıdaki satır geçerli, ikinci kayıt açılmaz (0.2.4+) |
| Başarısız zamanlanmış görev | `command_failed` — "Yol": `artisan rapor:gonder`. Sıfır olmayan çıkış kodu dahil. PHP yolu addan ve mesajdan atılır |
| Yalnızca kuyruk/zamanlayıcı çalıştıran uygulama | Canlılık nabzı iş bitiminde, kuyruk döngüsünde ve görev bitiminde de atılır; önbelleğe dakikada en fazla bir kez bakılır |
| Senkron kuyruk (`sync`, `deferred`) | İş isteğin içinde çalışır; isteğin ölçümü bozulmaz. Başarısız iş yine `job_failed` açar ve isteği işaretler |
| Octane | İstek ölçümü kurulur (`LARAVEL_OCTANE` ortam değişkeninden tanınır — `octane:start` bunu koyar; sunucuyu kendi başına başlatan kurulumlarda elle tanımlanmalı). Başlangıç zamanı istek anından alınır; sayaçlar istek sonunda ve kuyruk işi başlarken sıfırlanır |

**Bilinen sınırlar:**

- **`runInBackground()` görevleri** başarısız olduğunda Laravel `ScheduledTaskFailed`
  olayını üretmiyor; bu görevler için `command_failed` gelmez. Görev bir artisan
  komutuysa komutun kendi fırlattığı istisna yine `exception` olarak gelir.
- **Artisan görevi iki kayıt açabilir.** `Schedule::command()` komutu ayrı bir süreçte
  çalıştırır. Komut istisna fırlatırsa o süreç hatayı kendisi `exception` olarak
  raporlar (stack'li), zamanlayıcı da sıfır olmayan çıkışı `command_failed` olarak
  bildirir. İkisi farklı süreçlerde olduğu için elenemez; bilerek kabul edildi.
- **`job_failed` ve `command_failed` Laravel'in rapor ayarlarından geçmez.** Bu
  kayıtlar kuyruk/zamanlayıcı olayından açılır; handler'daki `dontReport`, `throttle`
  ve istisna eşleme (`$exceptions->map()`) bunlara uygulanmaz — yalnızca
  `vitalwatch.ignore` uygulanır. Eşleme kullanılıyorsa aynı arıza bir `job_failed`
  (özgün istisna) ve bir `exception` (eşlenmiş istisna) açabilir.

### Ölümcül hatalar ayrı bir tür

Bellek tükenmesi, zaman aşımı ve derleme hataları PHP'de **exception üretmez**: süreç ölür,
exception handler hiç çalışmaz. Yani siteyi gerçekten düşüren hata sınıfı, yalnızca
`hookExceptions()` ile izlendiğinde tamamen görünmez kalıyordu — uptime probu 500'ü görür
ama sebebini kimse bilmez.

`register_shutdown_function` ile yakalanıp `fatal` türüyle gönderilir. `exception` olarak
gönderilmedi çünkü ikisi farklı şeyler: exception uygulamanın yakalayabildiği bir durum,
fatal ise sunucunun sınırına çarpması — ve bu sınıfı ayrıca süzebilmek gerekiyor.

Bellek tükendiğinde raporlama kodunun kendisi de yer bulamaz; bu yüzden açılışta 256 KB'lık
bir tampon ayrılıp shutdown anında serbest bırakılır. Tampon olmadan kanca yazılır ama tam
ihtiyaç anında sessizce çalışmaz.

Yalnızca ölümcül türler (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`,
`E_USER_ERROR`) gönderilir. Uyarı ve bildirimler her istekte onlarca üretilebilir ve paneli
boğardı.

---

## Canlılık nabzı

Paket sekiz saatte bir hub'a **olay taşımayan** küçük bir istek gönderir.

Sebebi şu: hub bir kurulumun çalışıp çalışmadığını yalnızca gelen hatalardan
anlıyordu. Sonuç ters dönüyordu — hatasız çalışan bir uygulama hiç olay
göndermediği için "kurulum çalışmıyor olabilir" diye raporlanıyordu. Sağlıklı
olmak cezalandırılıyordu.

Artık kanıt isteğin kendisi. Canlılık isteği geldiği sürece hub kurulumun ayakta
olduğunu bilir; gelmediğinde söylediği şey gerçekten doğrudur.

| | |
|---|---|
| Aralık | 8 saat |
| Gövde | `{"events": []}` — hiçbir ölçüm taşımaz |
| Uç | Olayların gittiği uçla aynı, ek bir adres yok |
| Tetikleyen | HTTP isteği (aşağıya bakın) |

Aralık, hub'ın 24 saatlik sessizlik eşiğinin üçte biri. Eşitlenseydi tek bir
kaçırılan istek — deploy, kısa bir kesinti — kurulumu bozuk gösterirdi.

### Neden zamanlayıcıya bağlı değil

Canlılık isteği `terminate()` aşamasında, yani **yanıt kullanıcıya gönderildikten
sonra** atılır. Süresi gelmemişse yalnızca bir önbellek okuması yapılır;
kullanıcı hiçbir şey beklemez.

Laravel'in zamanlayıcısı kullanılmadı çünkü paket onlarca projeye kuruluyor
ve hepsinde çalışan bir cron olduğu varsayılamaz. Zamanlayıcısı olmayan bir
projede canlılık isteği hiç gitmez ve kurulum sessizce "bozuk" görünürdü — düzeltmeye
çalıştığı hatanın aynısı.

İsteğe bağlamanın ikinci faydası: **hub'ın kendi uptime probu da bir
istektir.** Hiç ziyaretçisi olmayan bir site bile prob sayesinde nabzını
atmaya devam eder.

Önbellek anahtarı proje anahtarıyla ayrılır; aynı cache deposunu paylaşan iki
uygulama birbirinin nabzını bastırmaz.

`VITALWATCH_ENABLED=false` iken ya da yapılandırma eksikken hiç gönderilmez.

---

## Ne toplamaz

Bunlar tasarım kısıtıdır, yapılandırmayla açılamaz:

- ❌ IP adresi
- ❌ User-Agent
- ❌ İstek gövdesi (form verisi)
- ❌ Query string değerleri
- ❌ Oturum verisi, çerez
- ❌ `user_id` (varsayılan kapalı, açıkça etkinleştirilmedikçe)
- ❌ Ham SQL literal değerleri — sorgular normalize edilir

Kayıttan önce mesaj ve stack trace içindeki e-posta, TC kimlik no, telefon, IBAN ve kart
numarası desenleri maskelenir.

---

## Davranış garantileri

Paket, kurulduğu uygulamanın davranışını **değiştirmez**:

- Exception'ı yutmaz, `throw` zincirini kesmez
- Kendi hatasını raporlamaya çalışmaz (sonsuz döngü riski)
- Hub'a ulaşamazsa sessizce vazgeçer, uygulamada hata üretmez
- Raporlama isteği kullanıcının isteğini bekletmez
- `VITALWATCH_ENABLED=false` iken hiçbir kanca kurulmaz

---

## Yapılandırma

`config/vitalwatch.php` içinde:

| Ayar | Varsayılan | Açıklama |
|---|---|---|
| `slow_request_ms` | 1000 | Bu süreyi aşan istekler `slow_request` olarak raporlanır |
| `slow_query_ms` | 500 | Bu süreyi aşan sorgular `slow_query` olarak raporlanır |
| `capture_user_id` | `false` | Açılırsa kimliği belirli kişiye bağlı veri toplanır |
| `ignore` | 6 sınıf¹ | Raporlanmayacak exception sınıfları (alt sınıflar da eşleşir) |
| `timeout` | 2 | Hub zaman aşımı, **saniye**. 100 ve üstü milisaniye sayılır; 0,1–10 sn aralığına kırpılır² |
| `release` | `VITALWATCH_RELEASE`, yoksa otomatik³ | Hatanın hangi deploy'la başladığını gösteren sürüm etiketi |
| `env` | `VITALWATCH_ENV`, yoksa `APP_ENV` | `production` · `staging` · `local`. Takma adlar çevrilir: `prod`/`live` → production, `stage`/`stg`/`preprod`/`uat` → staging, `dev`/`development`/`test`/`testing` → local. Tanınmayan değeri hub reddeder; teşhis komutu hata verir |

¹ `AuthenticationException`, `AuthorizationException`, `ValidationException`,
`TokenMismatchException`, `NotFoundHttpException`, `MethodNotAllowedHttpException`.
Ayrıca listeden bağımsız olarak **4xx taşıyan her hata** (`HttpException` 400–499,
`ModelNotFoundException`) raporlanmaz — uygulama onu kendisi günlüğe yazsa bile.
Tek istisna `job_failed`: kuyruk işinde `ModelNotFoundException` çağıranın değil işin
kendi arızasıdır ve bilerek raporlanır.

² Node paketi aynı adla milisaniye bekliyor; `VITALWATCH_TIMEOUT=2000` burada 2 saniye olur,
2000 saniye değil. Kesirli değer (`0.5`) desteklenir. Geçersiz, sıfır, negatif ya da
sonsuz değer varsayılana (2 sn) döner; sonuç 0,1–10 sn aralığına kırpılır (`50` → 10 sn,
`0.0001` → 0,1 sn). Kural bütün SDK'larda aynı.

³ **Sürüm etiketi otomatik bulunur (0.2.3+).** `VITALWATCH_RELEASE` neredeyse hiçbir kurulumda
doldurulmuyordu; hub bir hatanın hangi deploy'la başladığını söyleyemiyordu. Deploy'larımız
`git pull` olduğu için `.git` sunucuda duruyor ve etiket oradan okunabiliyor. Sıra (Node ve
Python paketleriyle aynı):

1. `VITALWATCH_RELEASE` — boşluk kırpılır, en fazla 64 karakter, olduğu gibi.
2. İlk dolu ortam değişkeni: `GIT_COMMIT`, `GIT_SHA`, `COMMIT_SHA`, `SOURCE_VERSION`,
   `VERCEL_GIT_COMMIT_SHA`, `RENDER_GIT_COMMIT`, `HEROKU_SLUG_COMMIT`, `CI_COMMIT_SHA`.
   Commit gibi görünüyorsa (7–40 onaltılık hane) küçük harfle ilk 12 hane, değilse en
   fazla 64 karakter. Çalışma anında okunur; `config:cache` etkilemez.
3. Proje kökündeki `.git`: `HEAD` → dal referansı ya da `packed-refs`; ayrık HEAD
   doğrudan commit. `.git` bir dosyaysa (`gitdir: …`, alt modül) gösterdiği dizin
   okunur. Geçerli 40 haneli commit'in ilk 12 hanesi.
4. Hiçbiri yoksa gönderilmez.

Kabuk komutu (`git`) çalıştırılmaz, yalnızca iki küçük dosya okunur. Sonuç süreçler arası
önbelleklenmez: FPM işçisi deploy'dan sağ çıkar ve önbellek eski commit'i raporlamaya devam
ederdi. Git worktree (`commondir`) desteklenmez — o durumda `VITALWATCH_RELEASE` kullanın.

### Hata gönderimi yanıtı bekletmez (0.2.3+)

HTTP isteği içinde oluşan hata ve yavaş sorgu olayı hemen gönderilmez; istek
bitiminde (terminate), yanıt kullanıcıya gittikten sonra gönderilir. Eskiden
`report()` yanıttan önce çalışıyor, hub yavaşsa kullanıcı zaman aşımı kadar
bekliyordu. Süreç terminate'e ulaşmadan biterse (exit, ölümcül hata) bekleyen
olaylar kapanışta gider. Konsol ve kuyrukta davranış değişmedi.

### Tek arıza, tek kayıt

Hata veren istek yalnızca **stack'li `exception`** kaydı açar; ölçüm aynı istek için
ayrıca `http_5xx` göndermez (0.2.3+). Hatasız dönen 5xx (`response('...', 503)`) yine
`http_5xx` olarak gelir.

Gövde hub'ın 8 KB sınırını aşarsa önce stack, sonra mesajın 200 karakter sonrası,
sonra en yavaş sorgu atılır — olay stack'siz de olsa ulaşır.

---

## Güvenlik

Sunucudan sunucuya gönderim HMAC ile imzalanır:

- `X-VitalWatch-Signature: sha256=<hmac_sha256(zaman_damgası + "." + gövde, VITALWATCH_SECRET)>`
  — damga imzaya dahil; olmasaydı eski bir gövde damgası değiştirilip yeniden oynatılabilirdi
- `X-VitalWatch-Timestamp` — 5 dakikadan eski istekler hub tarafından reddedilir (replay koruması)
- İmza karşılaştırması `hash_equals()` ile yapılır

**`VITALWATCH_SECRET` yalnızca sunucuda bulunur.** Tarayıcıya veya istemci tarafına asla
konulmaz — tarayıcı hataları için ayrı, anahtar tabanlı `t.js` kullanılır.

---

## Sürümleme

Semver. Hub'ın toplama ucu sözleşmesi geriye uyumlu tutulur: yeni alanlar eklenebilir,
mevcut alanlar kaldırılmaz. Eski paket sürümleri çalışmaya devam eder.

---

## Geliştirme

```bash
composer install
./vendor/bin/pest
./vendor/bin/pint
```

Yayınlama: `git tag v1.x.x && git push --tags` → Packagist webhook ile otomatik güncellenir.
