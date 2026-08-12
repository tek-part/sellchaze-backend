# خطة تطوير مجتمع Sellchaze إلى منصة B2B Social Commerce متكاملة

## 1. الرؤية والتميّز

المجتمع ليس نسخة B2C من Facebook أو TikTok. هدفه تحويل المحتوى إلى علاقة وصفقة B2B موثوقة:

- المورد يعرض منتجاً أو قدرة إنتاجية أو جولة مصنع أو شهادة.
- التاجر يطلب توريداً، يقارن العروض، يحفظ المورد ويتواصل معه.
- الشركة تنشر باسمها من خلال صلاحيات أعضاء الفريق.
- كل منشور تجاري يملك CTA واضحاً: طلب عرض سعر، إرسال استفسار، إضافة مورد، فتح المنتج، أو بدء محادثة.
- الثقة مبنية على التوثيق، سجل الشركة، القطاع، الاستجابة، وجودة التعامل وليس عدد الإعجابات فقط.

North Star Metric المقترح: **عدد العلاقات التجارية المؤهلة أسبوعياً الناتجة من المجتمع**، مثل RFQ أو استفسار منتج أو اتصال شركة تم من منشور.

## 2. تشخيص الوضع الحالي

المتوفر حالياً:

- Feed مصنف حسب القطاع والوقت مع page/cursor pagination.
- خمسة أنواع منشورات: منتج، عرض، RFQ، تحديث، سؤال.
- نشر بالحساب الشخصي أو باسم Organization.
- Rich text، إرفاق منتج، وروابط صور/فيديو/مستندات.
- إعجاب، تعليق ورد بمستوى واحد، مشاركة، حفظ، متابعة، حظر، كتم، وتبليغ.
- توثيق الشركات، صلاحية `post_as_company`، وحصة نشر حسب الباقة.
- Cache لنتيجة الـFeed لمدة 30 ثانية.

الفجوات:

- لا يوجد رفع ملفات حقيقي؛ المستخدم يضع URL يدوياً.
- لا يوجد Media lifecycle أو معالجة فيديو أو HLS أو resumable upload.
- الواجهة عمود واحد ولا تملك Navigation اجتماعي أو Trending/online/activity rail.
- إنشاء المنشور داخل Feed، وليس تجربة مستقلة مناسبة للمحتوى المتقدم.
- Ranking بسيط ولا يقيس نية الشراء أو جودة العلاقة التجارية.
- Cache الحالي per-viewer مع global generation؛ أي تعديل يبطل Cache الجميع.
- بيئة الإنتاج الحالية تعتمد file cache وqueue متزامن، وهذا غير مناسب للفيديو.
- لا توجد Reels surface أو creator analytics أو content scheduling/drafts.

## 3. Information Architecture والصفحات

### سطح المجتمع

| المسار | الغرض |
|---|---|
| `/feed` | Feed شخصي بثلاثة أعمدة على Desktop وعمود واحد على Mobile |
| `/community/create` | منشئ منشورات متقدم متعدد الخطوات مع Draft وPreview |
| `/community/post/:id` | صفحة منشور مستقلة، SEO/share preview، وكامل النقاش |
| `/reels` | عارض فيديو رأسي Full-screen مثل TikTok مع B2B actions |
| `/reels/create` | تصوير/رفع Reel، قص الغلاف، وصف، CTA ومنتج مرتبط |
| `/community/following` | منشورات الشركات والأشخاص المتابَعين |
| `/community/saved` | المحفوظات Collections وFolders |
| `/community/trending` | موضوعات ومنتجات وقطاعات رائجة |
| `/community/groups` | مجموعات حسب قطاع/مدينة/سلسلة توريد |
| `/community/groups/:slug` | Feed المجموعة، أعضاؤها، قواعدها وإدارتها |
| `/community/hashtag/:slug` | صفحة موضوع/هاشتاج |
| `/community/activity` | الإشعارات والتفاعلات والدعوات |
| `/company/:slug/posts` | محتوى الشركة وReels والمنتجات المنشورة |

### Layout `/feed`

- Header ثابت: بحث موحد عن شركات، منتجات، RFQs، مجموعات وأشخاص.
- Left rail ثابت: Feed، Following، Reels، Groups، Saved، Trending، My Company.
- Center: Stories/Highlights اختيارياً، Composer مختصر، فلاتر، Feed افتراضي.
- Right rail: RFQs مناسبة، شركات مقترحة، Topics رائجة، نشاط الشبكة.
- Mobile: Bottom navigation: Home، Discover، Create، Reels، Inbox.
- لا يتم نسخ ألوان المرجع؛ تبقى هوية Sellchaze الزرقاء مع مساحات بيضاء ووضوح B2B.

## 4. نظام إنشاء المنشورات

صفحة مستقلة وليست Form طويلة داخل Feed:

1. اختيار الهوية: شخصي أو شركة، مع صلاحيات ومراجعة اختيارية.
2. اختيار النوع: Update، Product، Offer، RFQ، Question، Case Study، Factory Tour، Event، Job.
3. المحرر: نص، mentions، hashtags، روابط مع OpenGraph preview، poll، location، catalog items.
4. Media tray: صور، فيديو، PDF، كتالوج، صوت؛ drag/drop، reorder، crop، alt text.
5. Business CTA: Request quotation، Contact supplier، View product، Register، Apply.
6. Audience: الجميع، المتابعون، القطاع، مجموعة، مناطق جغرافية، أعضاء محددون.
7. Publish controls: الآن، جدولة، Draft، approval flow، إغلاق التعليقات.
8. Preview: Desktop/Mobile/Arabic/English.

حالات المنشور:

`draft -> uploading -> processing -> review -> scheduled|published -> hidden|archived|rejected`

يجب فصل `post` عن `media asset` حتى لا يفشل المنشور أو يتكرر عند إعادة المحاولة.

## 5. Media Platform وChunk Upload

### قرار هندسي

الملفات لا تمر كاملة عبر PHP. المتصفح يرفع مباشرة إلى Object Storage متوافق مع S3، والخادم يصدر جلسة رفع وصلاحيات قصيرة المدة. يمكن البدء بـCloudflare R2 أو AWS S3 أو MinIO، مع CDN أمام الملفات المنشورة.

### بروتوكول الرفع

1. `POST /api/v2/media/uploads` يرسل الاسم والحجم وMIME وSHA-256 والغرض.
2. API يرجع `upload_id`, `asset_id`, `part_size`, pre-signed part URLs.
3. Client يقسم الملف إلى chunks بحجم 8–16 MB ويرفع 3–5 أجزاء بالتوازي.
4. كل جزء يسجل ETag؛ الحالة تحفظ في IndexedDB لاستكمال الرفع بعد refresh أو انقطاع الشبكة.
5. Pause/Resume/Cancel/Retry مع exponential backoff وnetwork awareness.
6. `POST /media/uploads/:id/complete` يثبت الأجزاء بشكل idempotent.
7. الخادم يتحقق من الحجم/checksum وMIME الحقيقي ثم يرسل `MediaUploaded` عبر Outbox.
8. Worker يعالج الملف، والواجهة تتابع الحالة عبر SSE/WebSocket مع fallback polling.

الرفع في الخلفية لا يعتمد فقط على Background Sync لأنها ليست متاحة بشكل موحد. الأساس هو resumable upload + IndexedDB، ويمكن Service Worker أن يكمل الأجزاء حين تسمح المنصة.

### معالجة الفيديو

- Virus scan وfile signature validation.
- FFprobe لاستخراج duration، dimensions، codec، bitrate.
- FFmpeg workers منفصلة تنتج HLS Adaptive Bitrate: 360p/480p/720p/1080p حسب المصدر.
- Poster، thumbnails، animated preview، waveform للصوت.
- Fast-start MP4 fallback، subtitles WebVTT، captions آلية في مرحلة لاحقة.
- فصل originals عن derivatives وسياسة lifecycle لحذف الأجزاء المؤقتة.
- حالات asset: `initiated/uploading/uploaded/scanning/processing/ready/failed/quarantined`.

### جداول رئيسية

- `media_assets`: owner, disk, object_key, kind, mime, size, checksum, status, metadata.
- `media_uploads`: upload token, multipart id, part size, expiry, progress, error.
- `media_variants`: asset, profile, format, dimensions, bitrate, object_key.
- `post_media`: post, asset, position, role, crop, alt_text.
- `media_processing_jobs`: stage, attempts, timings, error code.

## 6. تجربة Reels

### واجهة المشاهدة

- Feed رأسي snap، فيديو واحد نشط فقط، autoplay muted، tap للتشغيل، double-tap للتفاعل.
- Virtualized list: العنصر الحالي والسابق والتالي فقط في DOM.
- preload للـmanifest/poster وليس الفيديو الكامل؛ HLS adaptive حسب الشبكة.
- أزرار جانبية: reaction، comment drawer، save، share، more، follow.
- B2B actions ثابتة أسفل الوصف: Request Quote، View Product، Contact Company.
- company avatar، verified badge، sector، hashtags، product chips، captions.
- حفظ الموضع واستكمال الفيديو، keyboard navigation وreduced motion ودعم RTL.

### إنشاء Reel

- 9:16 مفضل، 15 ثانية إلى 3 دقائق في البداية، ثم حتى 10 دقائق حسب الباقة.
- trim، cover frame، caption، subtitles، audio normalization، product tagging.
- Templates: Product demo، Factory tour، Before/After، How it’s made، New arrival.
- حقوق صوت واضحة: مكتبة تجارية مرخصة أو صوت أصلي فقط في البداية.

### Ranking للـReels

لا يعتمد على watch time فقط. Score أولي:

`0.25 relevance + 0.20 qualified_watch + 0.15 business_intent + 0.10 trust + 0.10 relationship + 0.10 freshness + 0.10 quality - penalties`

- qualified_watch: نسبة المشاهدة والإعادة دون clickbait.
- business_intent: فتح منتج، طلب عرض، حفظ مورد، بدء محادثة.
- trust: توثيق، اكتمال الشركة، معدل الاستجابة، سلامة المحتوى.
- penalties: إخفاء/تبليغ، تكرار، spam، low-quality upload.

## 7. Feed Ranking واكتشاف المحتوى

### المرحلة الأولى

- Candidate sets: following، sector، groups، geography، trending، new companies، RFQs.
- قواعد قابلة للتفسير مع Feature flags وA/B experiments.
- منع أكثر من منشورين متتاليين لنفس المؤلف وتحقيق diversity للقطاع والنوع.
- cursor pagination فقط؛ لا page offsets للـFeed الأساسي.

### المرحلة المتقدمة

- `feed_events` append-only للأحداث: impression, 2s view, dwell, expand, like, save, comment, share, follow, product_view, rfq_start.
- Features مجمعة في Redis/warehouse ثم نموذج ranking offline/nearline.
- Explore/exploit بنسبة صغيرة لإعطاء الشركات الجديدة فرصة عادلة.
- فصل organic ranking عن sponsored content مع تسمية واضحة.

## 8. Caching والأداء

### المطلوب فوراً

- Redis لـcache, locks, rate limits, queues وpresence.
- Queue workers حقيقية عبر systemd/Supervisor أو Horizon؛ تقسيم queues: `critical`, `notifications`, `media`, `analytics`.
- CDN للصور/HLS مع immutable versioned URLs وsigned URLs للأصول الخاصة.
- Feed response cache قصير، Post card cache أطول، وcounter cache منفصل.

### Invalidation

إلغاء global generation الحالي واستبداله بـversioned keys محددة:

- `feed:v:{viewer}:{filter}:{cursor}` TTL 15–60s.
- `post:v:{post_id}` TTL 5–15m.
- `post:counters:{post_id}` Redis hash.
- invalidation tags من Events: PostPublished، PostUpdated، FollowChanged، ModerationChanged.
- Stale-while-revalidate وsingle-flight lock لمنع cache stampede.

### Feed generation عند النمو

- أقل من 100k مستخدم نشط: fan-out on read مع indexes وRedis candidate sets.
- المؤلفون العاديون: fan-out on write إلى inbox sorted sets.
- الحسابات الضخمة: fan-out on read لتجنب انفجار الكتابة.
- Hybrid merge عند القراءة مع deduplication وranking.

أهداف الأداء:

- Feed API p95 أقل من 250ms cached و600ms uncached.
- أول محتوى مرئي LCP أقل من 2.5s على 4G.
- بدء Reel أقل من 1.2s، rebuffer ratio أقل من 1%.
- نجاح استكمال الرفع بعد انقطاع الشبكة أكبر من 99%.

## 9. Real-time والإشعارات

- Events/Outbox لضمان عدم فقد الأحداث بعد commit.
- WebSocket/SSE للتعليقات، counters، حالة المعالجة، والإشعارات.
- Notification preferences حسب النوع والقناة: in-app، email، push لاحقاً.
- تجميع الإشعارات وعدم إرسال ضوضاء: “5 شركات تفاعلت مع منشورك”.
- Presence محدود للمحادثات والمجموعات، لا يتم عرضه لكل المجتمع دون حاجة.

## 10. الأمان والإشراف

- Content policy عربية/إنجليزية خاصة بالتجارة: بضائع محظورة، تضليل، انتحال، spam، أسعار وهمية.
- Rate limits حسب الهوية والفعل والجهاز، مع idempotency لكل publish/upload/interaction.
- Sanitization بقائمة HTML مسموحة؛ لا يكفي حذف script بالـregex.
- فحص الملفات، MIME sniffing، dimensions/duration limits، metadata stripping للصور.
- Moderation pipeline: قواعد -> ML provider اختياري -> human review -> appeals.
- Admin console: queue، priority، evidence snapshot، action history، bulk actions، SLA.
- حماية القُصّر والخصوصية ليست محور B2B، لكن GDPR/export/delete والاحتفاظ بالبيانات مطلوبة.
- Audit log لكل نشر باسم شركة أو قرار إشراف.

## 11. API المقترحة

تحت `/api/v2/community`:

- `GET /feed?scope=&sector=&type=&cursor=`
- `POST /posts`, `PATCH /posts/:id`, `DELETE /posts/:id`
- `POST /posts/:id/publish|schedule`
- `GET /posts/:id/comments?cursor=`, `POST /posts/:id/comments`
- `PUT /posts/:id/reactions/:type`, `DELETE /posts/:id/reactions/:type`
- `PUT /posts/:id/save`, `DELETE /posts/:id/save`
- `POST /posts/:id/share`
- `GET /reels?cursor=`, `POST /reels/:id/view-events`
- `POST /media/uploads`, `GET /media/uploads/:id`, `POST /media/uploads/:id/parts`
- `POST /media/uploads/:id/complete`, `DELETE /media/uploads/:id`
- `GET/POST /groups`, membership/moderation endpoints.
- `POST /events/batch` لإرسال analytics events بكفاءة.

كل endpoint كتابي يدعم `Idempotency-Key`، وجميع القوائم تستخدم cursor contract موحداً.

## 12. Clean Architecture وحدود الموديولات

يبقى Laravel Modular Monolith في هذه المرحلة، مع حدود واضحة:

- `Community`: posts, comments, reactions, groups, feeds.
- `Media`: uploads, assets, variants, processing.
- `Ranking`: candidates, scoring, experiments.
- `Moderation`: reports, policies, actions, appeals.
- `Notifications`: preferences, delivery, digests.
- `Analytics`: event ingestion and aggregates.

داخل كل Module: Domain -> Application Use Cases -> Infrastructure -> HTTP Resources. التواصل عبر Events وعقود، وليس استدعاءات متشابكة بين Controllers. لا يتم فصل Microservices قبل وجود حمل وتشغيل يبرران ذلك؛ يمكن فصل Media workers أولاً لأنها مختلفة في الموارد.

Frontend:

- route-level code splitting.
- feature folders: `community/feed`, `community/composer`, `community/reels`, `community/groups`.
- server state عبر TanStack Query أو طبقة cache موحدة، optimistic updates مع rollback.
- shared media uploader state machine وIndexedDB adapter.
- design tokens ومكونات Card/Avatar/ActionBar/MediaGrid موحدة.

## 13. مراحل التنفيذ

### Phase 0 — الأساس التشغيلي (أسبوعان)

- Redis production، workers، object storage، CDN، FFmpeg worker image.
- Events/Outbox، tracing، dashboards، feature flags.
- performance baseline وload tests للوضع الحالي.

**Gate:** queue retries وdead-letter واضحة، no data loss، وrollback مجرب.

### Phase 1 — Community UX وComposer (3 أسابيع)

- Feed بثلاثة أعمدة responsive وmobile bottom nav.
- `/community/create`، drafts، audience، company identity، mentions/hashtags.
- post detail، saved/following/trending، infinite cursor scroll.
- تحسين التعليقات والreactions والmenus بدل prompts الحالية.

**Gate:** publish success >99.9%، WCAG AA، p95 feed ضمن الهدف.

### Phase 2 — Media Upload Platform (3 أسابيع)

- multipart/chunk upload، resume، IndexedDB، progress، retry/cancel.
- image derivatives، documents، quotas، virus scan.
- ربط `post_media` وإزالة إدخال URL اليدوي من تجربة المستخدم.

**Gate:** ملفات 2GB تستكمل بعد انقطاع، checksum صحيح، cleanup jobs تعمل.

### Phase 3 — Video/Reels MVP (4 أسابيع)

- FFmpeg/HLS pipeline، poster/captions، `/reels` و`/reels/create`.
- player virtualization، adaptive streaming، view event contract.
- product/RFQ CTA وanalytics الأساسية.

**Gate:** start time/rebuffer ضمن الأهداف، فشل المعالجة قابل لإعادة المحاولة.

### Phase 4 — Groups, Discovery, Trust (3 أسابيع)

- groups، topics، unified community search، company content tabs.
- verification cues، moderation console، appeals، business recommendations.

### Phase 5 — Ranking وGrowth (4 أسابيع)

- event pipeline، rule-based score v2، experiments، creator insights.
- notifications digest، follow suggestions، onboarding loops.
- sponsored posts بعد تثبيت organic quality والسياسات.

### Phase 6 — Scale hardening (مستمر)

- hybrid fan-out، read replicas عند الحاجة، archival/partitioning، multi-region media CDN.
- chaos tests، disaster recovery، capacity planning وتكلفة لكل 1,000 مشاهدة.

المدة الواقعية لإصدار قوي: **16–19 أسبوعاً** بفريق 5–7 أشخاص (2 Backend، 2 Frontend، DevOps/Media، QA، Product/Design). يمكن إطلاق قيمة واضحة بعد Phase 1 خلال 5 أسابيع، وReels MVP بعد نحو 12 أسبوعاً.

## 14. الاختبارات وبوابات الجودة

- Contract tests للـAPI وidempotency.
- Feature tests للصلاحيات والنشر باسم الشركة والعزل.
- Upload interruption، duplicate complete، corrupted part، expired URL.
- Video fixtures متعددة codecs/orientation/duration وworker retry tests.
- Feed ranking invariants: blocked content never appears، diversity، no duplicates.
- Browser tests للـRTL/LTR/mobile/keyboard/accessibility.
- k6 load scenarios: feed burst، viral post counters، chunk completion، comment storm.
- Visual regression للـFeed وReels.
- Security tests: XSS، SSRF عبر روابط المعاينة، zip bombs، malicious MIME.

لا ينشر أي Phase دون metrics، alerting، migration rollback، وfeature flag تدريجي 1% -> 10% -> 50% -> 100%.

## 15. مؤشرات المنتج

- Activation: نشر/متابعة/تفاعل مفيد خلال أول 7 أيام.
- DAU/WAU وعودة D1/D7/D30 حسب Merchant/Supplier.
- Qualified engagement rate، لا likes فقط.
- نسبة منشور -> product view -> inquiry/RFQ -> deal.
- زمن أول استجابة من المورد، ومعدل الرد.
- Reel completion وqualified CTA rate.
- نسبة المحتوى المبلغ عنه، زمن قرار الإشراف، ونسبة appeals المقبولة.
- Upload success، processing time، playback start، rebuffer، CDN hit rate.

## 16. أول Backlog قابل للتنفيذ

1. إضافة Redis + async queue إلى الإنتاج وتحديث runbook.
2. إنشاء Module Media وجداول assets/uploads/variants/post_media.
3. بناء multipart upload contract وواجهة uploader تجريبية خلف feature flag.
4. إعادة تصميم Feed shell بثلاثة أعمدة دون كسر PostCard الحالي.
5. نقل PostComposer إلى `/community/create` مع quick composer في Feed.
6. تحويل Feed إلى cursor pagination + infinite virtualization.
7. استبدال cache global flush بـevent-scoped invalidation.
8. إضافة event ingestion لأحداث impression/dwell/business CTA.
9. بناء image pipeline ثم video worker/HLS.
10. إطلاق Reels لمجموعة beta موثقة قبل التعميم.

