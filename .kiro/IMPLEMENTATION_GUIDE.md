# دليل التطبيق - الخطط الترويجية والعرض الافتتاحي

## 📋 الملخص التنفيذي

تم إنشاء نظام شامل للخطط الترويجية يمكنك من:
- ✅ إنشاء خطط ترويجية متعددة (3 شهور، 6 شهور، سنة مجانية، إلخ)
- ✅ ربط الخطط بالمستخدمين الجدد من الواجهة الأمامية
- ✅ حماية الخطة التجريبية من التعديل (أمان 🔒)
- ✅ إدارة الخطط الأخرى بسهولة

---

## 🚀 البدء السريع (Quick Start)

### الخطوة 1: التحقق من وجود الخطة الترويجية

خطة `promo_3months` موجودة في seed الإنتاج المعتمد `database/production/smartsys_production_core_seed_20261002.sql`. لا تُدخلها يدويًا ولا تستورد seed إضافيًا.

### الخطوة 2: تحقق من إضافة الخطة

في phpMyAdmin أو من الواجهة الأمامية:
1. اذهب إلى **Plans Management**
2. يجب أن ترى الخطة الجديدة في القائمة
3. تحقق من البيانات: الكود، المدة، السعر

### الخطوة 3: ربط الخطة بمستخدم جديد

من صفحة **Subscriptions**:
1. ابحث عن المستخدم الجديد
2. اضغط على **تفعيل الاشتراك** (Activate)
3. اختر `promo_3months` من قائمة الخطط
4. اضغط **تفعيل**

✅ تم! المستخدم لديه الآن 3 أشهر مجانية!

---

## 🛠️ خيارات التخصيص

### إنشاء خطط ترويجية مختلفة

#### 1. عرض صيفي (خصم 50%)
```sql
INSERT INTO `plans` VALUES (
    NULL, 'promo_summer', 'Summer Offer - 50% Off',
    'عرض صيفي - خصم 50%',
    125.00, 'EGP', 30, 1, NOW(), NOW()
);
```

#### 2. عرض رمضاني (شهر مجاني)
```sql
INSERT INTO `plans` VALUES (
    NULL, 'promo_ramadan', 'Ramadan Special',
    'عرض رمضاني - شهر مجاني',
    0.00, 'EGP', 30, 1, NOW(), NOW()
);
```

#### 3. برنامج ولاء VIP (سنة مجانية)
```sql
INSERT INTO `plans` VALUES (
    NULL, 'promo_vip', 'VIP Loyalty Program',
    'برنامج ولاء - سنة مجانية',
    0.00, 'EGP', 365, 1, NOW(), NOW()
);
```

### إضافة من الواجهة الأمامية

بدلاً من SQL، يمكنك:
1. الذهاب إلى **Plans**
2. اضغط **إضافة خطة جديدة**
3. املأ البيانات وحفظ ✅

---

## 🔒 سياسة الأمان

### الخطط المحمية (Protected Plans)
- **Trial** (trial) - محمية من التعديل ❌
  - الكود لا يمكن تغييره
  - السعر والمدة لا يمكن تغييرها
  - الهدف: منع استغلال الخطة الافتراضية

### الخطط القابلة للتعديل (Editable Plans)
- **Monthly** (monthly) ✅
- **Yearly** (yearly) ✅
- **جميع الخطط الترويجية** (promo_*) ✅
  - يمكن تعديل الأسعار والمدة
  - تنطبق التغييرات على الاشتراكات الجديدة فقط

### Backend Protection
```php
// في AdminSubscriptionHandler.php
// فقط monthly و yearly يمكن تعديلهم
if (!in_array($code, ['monthly', 'yearly'], true)) {
    return $this->errorResponse($response, 'Only monthly/yearly can be updated', 400);
}
```

---

## 📊 مثال عملي: عرض افتتاحي شامل

### السيناريو:
تريد تقديم عرض افتتاحي:
- 3 أشهر مجانية لأول 100 مستخدم جديد
- ثم تحويلهم للخطة الشهرية (250 EGP)

### التطبيق:

#### 1. إنشاء الخطة الترويجية
```sql
INSERT INTO `plans` VALUES (
    NULL, 'promo_opening', 'Grand Opening - 3 Months Free',
    'عرض الافتتاح - 3 أشهر مجانية',
    0.00, 'EGP', 90, 1, NOW(), NOW()
);
```

#### 2. تسجيل المستخدمين
- المستخدمون الجدد يسجلون عادة

#### 3. ربط الخطة الترويجية
- Admin يدخل صفحة Subscriptions
- يختار كل مستخدم جديد
- يضغط **Activate** واختيار `promo_opening`
- المستخدم يحصل على 3 أشهر مجانية ✅

#### 4. التحويل للخطة المدفوعة (بعد 3 أشهر)
- النظام يرسل تنبيه قبل انتهاء الفترة
- المستخدم يمكنه شراء الخطة الشهرية
- أو يحصل على تجربة Trial جديدة

---

## 📱 API الخطط

### الحصول على جميع الخطط
```bash
GET /api/v1/admin/plans
```

**الاستجابة:**
```json
{
  "status": "success",
  "data": {
    "items": [
      {
        "code": "trial",
        "name": "Trial 14 days",
        "price": "0.00",
        "currency": "EGP",
        "billing_cycle_days": 14,
        "is_active": 1
      },
      {
        "code": "promo_3months",
        "name": "Promotional 3 Months",
        "price": "0.00",
        "currency": "EGP",
        "billing_cycle_days": 90,
        "is_active": 1
      }
    ]
  }
}
```

### إضافة خطة جديدة
```bash
POST /api/v1/admin/plans
Content-Type: application/json

{
  "code": "promo_ramadan",
  "name": "Ramadan Special",
  "price": 0,
  "currency": "EGP",
  "billing_cycle_days": 30,
  "is_active": 1
}
```

### تعديل خطة (monthly/yearly فقط)
```bash
PUT /api/v1/admin/plans/monthly
Content-Type: application/json

{
  "price": 300,
  "currency": "EGP",
  "billing_cycle_days": 30
}
```

### تفعيل خطة للمستخدم
```bash
POST /api/v1/admin/subscriptions/{id}/activate
Content-Type: application/json

{
  "plan": "promo_3months"
}
```

---

## ⚠️ الأخطاء الشائعة والحلول

| الخطأ | السبب | الحل |
|------|------|------|
| Plan not found | الخطة غير موجودة | تحقق من كود الخطة |
| Only monthly/yearly can be updated | محاولة تعديل trial | الخطط الأخرى فقط |
| Duplicate entry | الخطة موجودة بالفعل | الخطة أُضيفت بنجاح ✅ |
| Invalid plan code | كود الخطة فارغ | أدخل كود صحيح |

---

## 📝 توثيق مرتبط

- **database/production/README.md** — حزمة قاعدة البيانات وتهيئة الإنتاج.
- **database/migrations/README.md** — تطبيق migrations والتحقق منها.
- **.kiro/docs/PROMOTIONAL_PLANS.md** — توثيق الخطط الترويجية.
   - سيناريوهات عملية

---

## ✅ قائمة التحقق (Checklist)

- [ ] تطبيق هجرة الخطة الترويجية
- [ ] التحقق من ظهور الخطة في Plans Management
- [ ] اختبار ربط الخطة بمستخدم جديد
- [ ] التحقق من المدة (90 يوم)
- [ ] التحقق من السعر (0.00)
- [ ] اختبار من API (إذا أردت)
- [ ] توثيق الخطط الجديدة في النظام
- [ ] إخبار فريق المبيعات بالخطط الجديدة

---

## 🎯 النتيجة النهائية

✅ نظام كامل للخطط الترويجية
✅ حماية آمنة للخطط الأساسية
✅ مرونة في إنشاء عروض جديدة
✅ سهولة الربط والإدارة من الواجهة الأمامية

**الآن يمكنك:**
- إطلاق عروض ترويجية بسهولة
- جذب مستخدمين جدد
- زيادة معدلات التحويل للخطط المدفوعة
- إدارة كل شيء من لوحة التحكم ✨
