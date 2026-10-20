# 🔒 Security Policy | سياسة الأمان

## 🛡️ Supported Versions | الإصدارات المدعومة

نحن نقدم تحديثات أمنية للإصدارات التالية:

We provide security updates for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |
| < 1.0   | :x:                |

---

## 🚨 Reporting a Vulnerability | الإبلاغ عن ثغرة أمنية

نحن نأخذ أمان SmartSys على محمل الجد. إذا اكتشفت ثغرة أمنية، نرجو اتباع الإجراءات التالية:

We take the security of SmartSys seriously. If you discover a security vulnerability, please follow these steps:

### ⚠️ **لا تفتح Issue عام | DO NOT open a public issue**

بدلاً من ذلك، يرجى إرسال تقرير مباشر عبر:

Instead, please report directly via:

1. **البريد الإلكتروني | Email**: [أضف بريدك هنا | Add your email here]
2. **GitHub Security Advisory**: استخدم خاصية [Private Security Reporting](https://github.com/Hosniofficial/SmartSys-platform/security/advisories/new)

### 📝 ما يجب تضمينه في التقرير | What to include in the report

يرجى تضمين أكبر قدر من المعلومات التالية:

Please include as much of the following information as possible:

- **نوع الثغرة | Type of vulnerability**: (مثل: SQL injection, XSS, CSRF, etc.)
- **المسار/الملف المتأثر | Affected path/file**: (مثل: `/api/v1/auth/login`)
- **الخطوات لإعادة الإنتاج | Steps to reproduce**: شرح مفصل خطوة بخطوة
- **التأثير المحتمل | Potential impact**: ما الذي يمكن للمهاجم فعله؟
- **النسخة المتأثرة | Affected version**: (مثل: 1.0.0)
- **POC (إثبات المفهوم) | Proof of Concept**: كود أو screenshots إن أمكن
- **الحل المقترح | Suggested fix**: إن كان لديك فكرة

### ⏱️ وقت الاستجابة | Response Timeline

نلتزم بالرد على تقارير الثغرات الأمنية بسرعة:

We commit to responding to security vulnerability reports quickly:

- **التأكيد الأولي | Initial acknowledgment**: خلال 48 ساعة
- **تحديث التقدم | Progress update**: خلال 5 أيام عمل
- **الإصلاح الأولي | Initial fix**: حسب الخطورة (1-30 يوم)

### 🎖️ مكافآت الثغرات | Bug Bounty

حالياً لا يوجد برنامج مكافآت رسمي، لكننا نقدّر بشدة:

Currently there is no formal bounty program, but we greatly appreciate:

- ✨ شكر خاص في ملف [SECURITY.md](SECURITY.md)
- ✨ ذكر في ملاحظات الإصدار عند نشر الإصلاح
- ✨ شارة "Security Researcher" في ملفك على GitHub

---

## 🔐 ممارسات الأمان | Security Best Practices

### للمطورين | For Developers

عند المساهمة في المشروع، يرجى اتباع:

When contributing to the project, please follow:

#### 1. **التحقق من المدخلات | Input Validation**
```php
// ✅ DO
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);

// ❌ DON'T
$result = $pdo->query("SELECT * FROM users WHERE id = $userId");
```

#### 2. **استخدام Prepared Statements**
- دائماً استخدم prepared statements للاستعلامات
- لا تدمج المدخلات مباشرة في SQL

#### 3. **تجنب XSS**
```javascript
// ✅ DO - استخدم v-text أو {{ }}
<div>{{ userInput }}</div>

// ❌ DON'T - تجنب v-html مع محتوى المستخدم
<div v-html="userInput"></div>
```

#### 4. **إدارة الأسرار | Secrets Management**
- لا تضع أسرار في الكود
- استخدم `.env` لجميع البيانات الحساسة
- لا ترفع `.env` على Git
- استخدم secrets managers في الإنتاج (AWS Secrets Manager, HashiCorp Vault)

#### 5. **التحقق من الصلاحيات | Authorization Checks**
```php
// ✅ DO - تحقق من الصلاحيات في كل endpoint
$this->permissionMiddleware->requirePermission('products.create');

// ❌ DON'T - لا تفترض أن المستخدم لديه صلاحية
```

#### 6. **Rate Limiting**
- طبّق rate limiting على endpoints الحساسة
- استخدم معدلات مختلفة حسب نوع الـ endpoint

### للمستخدمين | For Users

#### 1. **كلمات مرور قوية | Strong Passwords**
- استخدم كلمات مرور معقدة (12+ حرف)
- استخدم مزيجاً من الأحرف والأرقام والرموز
- لا تعد استخدام نفس كلمة المرور

#### 2. **تفعيل 2FA**
- فعّل المصادقة الثنائية لجميع الحسابات
- احفظ رموز الاسترداد في مكان آمن

#### 3. **تحديثات منتظمة | Regular Updates**
- حدّث النظام فور توفر التحديثات الأمنية
- راقب إعلانات الإصدارات والتحديثات الأمنية الرسمية

#### 4. **مراجعة الصلاحيات | Review Permissions**
- راجع صلاحيات المستخدمين بانتظام
- طبّق مبدأ "الصلاحيات الأدنى" (Least Privilege)

#### 5. **النسخ الاحتياطي | Backups**
- احتفظ بنسخ احتياطية منتظمة
- خزّن النسخ الاحتياطية في مكان آمن ومنفصل

---

## 🔍 الثغرات المعروفة | Known Vulnerabilities

لا توجد ثغرات أمنية معروفة حالياً.

No known vulnerabilities at this time.

### تاريخ الثغرات المحلولة | Resolved Vulnerabilities History

سيتم توثيق الثغرات المحلولة هنا بعد الإصلاح.

Resolved vulnerabilities will be documented here after fixes.

---

## 📚 موارد إضافية | Additional Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [CWE Top 25](https://cwe.mitre.org/top25/)
- [PHP Security Best Practices](https://www.php.net/manual/en/security.php)
- [Vue.js Security Best Practices](https://vuejs.org/guide/best-practices/security.html)

---

## 📞 التواصل | Contact

للأسئلة غير الأمنية، استخدم:

For non-security questions, use:

- GitHub Issues: للمشاكل التقنية
- GitHub Discussions: للأسئلة العامة

---

**شكراً لمساعدتك في الحفاظ على أمان SmartSys! 🙏**

**Thank you for helping keep SmartSys secure! 🙏**
