# cestnytr/vitalwatch-laravel — Çalışma Kuralları

Bu paket **izlenen projelere** kurulur. Hub'ın kendisi ayrı repodadır:
[vitalwatch-hub](https://github.com/CestnyTR/vitalwatch-hub) — tam şartname orada `docs/02-spec.md`.

## Dil

Yorumlar ve dokümantasyon Türkçe; sınıf/metot/değişken adları İngilizce.
**README ve public API dokümantasyonu Türkçe** — bu paketi kuran ekip Türkçe çalışıyor.

---

## Bu paket public bir repodur

- **Hiçbir sır koda girmez.** Anahtar ve secret `.env`'den okunur.
- Örneklerde gerçek anahtar, gerçek alan adı dışında iç bilgi kullanılmaz.
- MIT lisanslı; Packagist üzerinden dağıtılır.

---

## Bağlayıcı kısıtlar

### Toplanmayacaklar

Bunlar yapılandırmayla dahi açılamaz — kodda karşılığı bulunmamalı:

- IP adresi
- User-Agent
- İstek gövdesi / form verisi
- Query string **değerleri** (yalnızca yol saklanır)
- Oturum verisi, çerez
- Ham SQL literal değerleri

`user_id` yalnızca `capture_user_id=true` ile ve açıkça toplanır; varsayılan `false`.

### SQL normalizasyonu zorunlu

`where email = 'ahmet@ornek.com'` → `where email = ?`

Literal değerler kaydedilmeden önce temizlenir. Bu hem KVKK gereği hem de gruplama için
doğru davranıştır: aynı sorgu farklı parametrelerle çalıştığında tek parmak izinde toplanır.

### Desen maskeleme

`msg` ve `stack` gönderilmeden önce e-posta, TC kimlik no, telefon, IBAN ve kart numarası
desenleri maskelenir.

---

## Davranış garantileri — ihlal edilemez

Paket, kurulduğu uygulamayı **hiçbir şekilde etkilememelidir**:

1. **Exception yutulmaz.** Handler zinciri korunur, `throw` engellenmez.
2. **Kendi hatasını raporlamaz.** Sonsuz döngü riski; tüm SDK kodu `try/catch` ile sarılır
   ve hata sessizce yutulur.
3. **Kullanıcı isteğini bekletmez.** Raporlama isteği kısa zaman aşımıyla (varsayılan 2 sn)
   yapılır; mümkünse `terminate` aşamasında veya kuyrukta gönderilir.
4. **Hub erişilemezse sessizce vazgeçilir.** İzlenen uygulamada hiçbir hata, log kirliliği
   veya yavaşlama oluşmaz.
5. **`VITALWATCH_ENABLED=false` iken hiçbir kanca kurulmaz** — sıfır ek yük.

> Bir izleme paketinin izlediği uygulamayı bozması, çözdüğü sorundan büyük bir sorundur.
> Şüphe halinde daha az iş yapan seçenek tercih edilir.

---

## Teknik tercihler

| Konu | Karar |
|---|---|
| Laravel desteği | `^11.0 \|\| ^12.0 \|\| ^13.0` |
| PHP | `^8.2` |
| Namespace | `CestnyTR\VitalWatch\` |
| Provider | `CestnyTR\VitalWatch\VitalWatchServiceProvider` — paket keşfiyle otomatik |
| Exception kancası | `bootstrap/app.php` `withExceptions` (Laravel 11+) |
| Yavaş sorgu | `DB::listen` |
| Yavaş istek | Middleware + `terminate` |
| Test | Pest |
| Kod stili | Pint |

---

## Bağımlılık politikası

**Mümkün olan en az bağımlılık.** Bu paket 13 farklı projeye kurulacak; her bağımlılık
sürüm çakışması riski demektir. Laravel'in kendi bileşenleri dışında paket eklenmez.
