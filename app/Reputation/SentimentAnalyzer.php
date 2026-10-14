<?php
/**
 * Tourfecto - Sentiment Analyzer
 * تحليل المشاعر في النصوص باستخدام AI
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class SentimentAnalyzer {
    /**
     * @var TourfectoAIEngine $aiEngine - محرك الذكاء الاصطناعي
     */
    private $aiEngine;
    
    /**
     * @var array $keywords - قوائم الكلمات المفتاحية
     */
    private $keywords = [
        'positive' => [
            'رائع', 'ممتاز', 'جيد', 'جميل', 'مذهل', 'أفضل', 'مثالي', 'سعيد', 'شكراً',
            'good', 'great', 'excellent', 'amazing', 'best', 'perfect', 'happy', 'thanks',
            'wonderful', 'fantastic', 'outstanding', 'superb', 'brilliant', 'awesome'
        ],
        'negative' => [
            'سيئ', 'مخيب', 'فاشل', 'رديء', 'محبط', 'غاضب', 'أسوأ', 'سيء', 'كئيب',
            'bad', 'terrible', 'awful', 'disappointed', 'worst', 'angry', 'poor',
            'horrible', 'dreadful', 'unacceptable', 'frustrating', 'miserable'
        ]
    ];
    
    /**
     * @var array $arabicStemmer - معالج الجذور العربية
     */
    private $arabicStemmer;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->aiEngine = new TourfectoAIEngine();
        $this->arabicStemmer = $this->initArabicStemmer();
    }
    
    /**
     * تحليل المشاعر في النص
     * @param string $text - النص المراد تحليله
     * @param int $userId - معرف المستخدم
     * @return array
     */
    public function analyze(string $text, int $userId): array {
        // تحديد اللغة
        $language = $this->detectLanguage($text);
        
        // استخدام AI لتحليل المشاعر
        $aiResult = $this->aiEngine->analyzeSentiment($text, $userId);
        
        // إذا نجح AI، استخدم نتيجته
        if ($aiResult['label'] !== 'unknown') {
            return $aiResult;
        }
        
        // استخدام التحليل التقليدي كـ Fallback
        return $this->traditionalAnalysis($text, $language);
    }
    
    /**
     * تحليل المشاعر التقليدي (Fallback)
     * @param string $text
     * @param string $language
     * @return array
     */
    public function traditionalAnalysis(string $text, string $language = 'ar'): array {
        $text = $this->normalizeText($text, $language);
        
        $positiveScore = 0;
        $negativeScore = 0;
        
        // تحليل الكلمات
        $words = $this->tokenizeText($text, $language);
        
        foreach ($words as $word) {
            $word = $this->stemWord($word, $language);
            
            if (in_array($word, $this->keywords['positive'])) {
                $positiveScore++;
            }
            
            if (in_array($word, $this->keywords['negative'])) {
                $negativeScore++;
            }
        }
        
        // حساب الدرجة النهائية
        $total = $positiveScore + $negativeScore;
        
        if ($total === 0) {
            return [
                'label' => 'neutral',
                'score' => 0.5,
                'confidence' => 0.3,
                'positive_words' => 0,
                'negative_words' => 0,
                'total_words' => count($words)
            ];
        }
        
        $score = $positiveScore / $total;
        $label = 'neutral';
        
        if ($score > 0.6) {
            $label = 'positive';
        } elseif ($score < 0.4) {
            $label = 'negative';
        }
        
        return [
            'label' => $label,
            'score' => $score,
            'confidence' => min(1, $total / 20),
            'positive_words' => $positiveScore,
            'negative_words' => $negativeScore,
            'total_words' => count($words)
        ];
    }
    
    /**
     * تحليل المشاعر على مستوى الجمل
     * @param string $text
     * @param int $userId
     * @return array
     */
    public function analyzeSentences(string $text, int $userId): array {
        $sentences = $this->splitSentences($text);
        $results = [];
        
        foreach ($sentences as $sentence) {
            $sentiment = $this->analyze($sentence, $userId);
            $results[] = [
                'sentence' => $sentence,
                'sentiment' => $sentiment
            ];
        }
        
        return $results;
    }
    
    /**
     * تحليل المشاعر على جوانب متعددة
     * @param string $text
     * @param array $aspects
     * @param int $userId
     * @return array
     */
    public function analyzeAspects(string $text, array $aspects, int $userId): array {
        $results = [];
        
        foreach ($aspects as $aspect) {
            // البحث عن الجمل التي تتحدث عن هذا الجانب
            $relevantSentences = $this->findRelevantSentences($text, $aspect);
            
            if (empty($relevantSentences)) {
                $results[$aspect] = [
                    'sentiment' => 'neutral',
                    'score' => 0.5,
                    'confidence' => 0
                ];
                continue;
            }
            
            // تحليل المشاعر للجمل ذات الصلة
            $combinedText = implode(' ', $relevantSentences);
            $sentiment = $this->analyze($combinedText, $userId);
            
            $results[$aspect] = $sentiment;
        }
        
        return $results;
    }
    
    /**
     * اكتشاف لغة النص
     * @param string $text
     * @return string
     */
    public function detectLanguage(string $text): string {
        // الكشف عن اللغة العربية
        $arabicPattern = '/[\x{0600}-\x{06FF}]/u';
        if (preg_match($arabicPattern, $text)) {
            return 'ar';
        }
        
        // الكشف عن اللغة الإنجليزية
        $englishPattern = '/[a-zA-Z]/';
        if (preg_match($englishPattern, $text)) {
            return 'en';
        }
        
        return 'unknown';
    }
    
    /**
     * تطبيع النص
     * @param string $text
     * @param string $language
     * @return string
     */
    private function normalizeText(string $text, string $language = 'ar'): string {
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);
        
        if ($language === 'ar') {
            // تطبيع الحروف العربية
            $text = str_replace(
                ['آ', 'أ', 'إ', 'ٱ', 'ٲ', 'ٳ'],
                'ا',
                $text
            );
            
            $text = str_replace(
                ['ة', 'ه'],
                'ه',
                $text
            );
            
            $text = str_replace(
                ['ي', 'ئ', 'ؤ'],
                'ي',
                $text
            );
            
            $text = str_replace(
                ['ى', 'ﻰ', 'ﻱ'],
                'ي',
                $text
            );
            
            // إزالة التشكيل
            $text = preg_replace('/[\x{064B}-\x{065F}]/u', '', $text);
        }
        
        // تحويل إلى أحرف صغيرة
        $text = strtolower($text);
        
        // إزالة علامات الترقيم
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        
        return $text;
    }
    
    /**
     * تقسيم النص إلى كلمات
     * @param string $text
     * @param string $language
     * @return array
     */
    private function tokenizeText(string $text, string $language = 'ar'): array {
        $text = $this->normalizeText($text, $language);
        $words = preg_split('/\s+/', $text);
        
        // إزالة الكلمات القصيرة جداً
        $words = array_filter($words, function($word) use ($language) {
            if ($language === 'ar') {
                return strlen($word) > 2;
            }
            return strlen($word) > 2;
        });
        
        return $words;
    }
    
    /**
     * استخراج جذر الكلمة
     * @param string $word
     * @param string $language
     * @return string
     */
    private function stemWord(string $word, string $language = 'ar'): string {
        if ($language === 'ar' && $this->arabicStemmer) {
            return $this->arabicStemmer->stem($word);
        }
        
        // تحسين بسيط للغة الإنجليزية
        if ($language === 'en') {
            // إزالة اللواحق الشائعة
            $suffixes = ['ing', 'ed', 'es', 's', 'ly', 'ful', 'ness', 'ment'];
            foreach ($suffixes as $suffix) {
                if (substr($word, -strlen($suffix)) === $suffix) {
                    $word = substr($word, 0, -strlen($suffix));
                    break;
                }
            }
        }
        
        return $word;
    }
    
    /**
     * تقسيم النص إلى جمل
     * @param string $text
     * @return array
     */
    private function splitSentences(string $text): array {
        // تقسيم حسب علامات الترقيم
        $sentences = preg_split('/[.!?]+[\s\n]+/', $text);
        
        // إزالة الجمل الفارغة
        $sentences = array_filter($sentences, function($sentence) {
            return strlen(trim($sentence)) > 5;
        });
        
        return $sentences;
    }
    
    /**
     * البحث عن الجمل ذات الصلة بجانب معين
     * @param string $text
     * @param string $aspect
     * @return array
     */
    private function findRelevantSentences(string $text, string $aspect): array {
        $sentences = $this->splitSentences($text);
        $relevant = [];
        
        $aspectWords = explode(' ', strtolower($aspect));
        
        foreach ($sentences as $sentence) {
            $sentenceLower = strtolower($sentence);
            
            foreach ($aspectWords as $word) {
                if (strpos($sentenceLower, $word) !== false) {
                    $relevant[] = $sentence;
                    break;
                }
            }
        }
        
        return $relevant;
    }
    
    /**
     * تهيئة معالج الجذور العربية
     * @return object|null
     */
    private function initArabicStemmer() {
        // محاولة استخدام مكتبة Arabic Stemmer
        if (class_exists('ArabicStemmer')) {
            return new ArabicStemmer();
        }
        
        // تطبيق بسيط للجذور العربية
        return new class {
            public function stem($word) {
                // إزالة البادئات الشائعة
                $prefixes = ['ال', 'و', 'ف', 'ب', 'ك', 'ل', 'م', 'ت', 'ن', 'ي'];
                foreach ($prefixes as $prefix) {
                    if (strpos($word, $prefix) === 0) {
                        $word = substr($word, strlen($prefix));
                        break;
                    }
                }
                
                // إزالة اللواحق الشائعة
                $suffixes = ['ون', 'ين', 'ات', 'ان', 'ية', 'يات', 'ي', 'ة'];
                foreach ($suffixes as $suffix) {
                    if (substr($word, -strlen($suffix)) === $suffix) {
                        $word = substr($word, 0, -strlen($suffix));
                        break;
                    }
                }
                
                return $word;
            }
        };
    }
}