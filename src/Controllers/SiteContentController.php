<?php
declare(strict_types=1);

final class SiteContentController
{
    private PDO $db;
    public function __construct() { $this->db = Database::connection(); }

    public static function definitions(): array
    {
        return [
            'announcement' => [
                'announcement_active'=>['Show announcement bar','1','checkbox'],
                'announcement_before'=>['Text before highlighted time','🚚 Same-day delivery across Accra for orders before','text'],
                'announcement_time'=>['Highlighted time','10:00 AM','text'],
                'announcement_after'=>['Text after highlighted time','• Pay with Mobile Money or cash on delivery','text'],
            ],
            'store' => [
                'store_accepting_orders'=>['Accept new checkout orders','1','checkbox'],
                'store_closed_message'=>['Closed message','Online ordering is temporarily paused. Please contact us on WhatsApp or try again later.','textarea'],
            ],
            'hero' => [
                'hero_eyebrow'=>['Hero eyebrow','Pure Refreshment, squeezed to perfection','text'],
                'hero_heading'=>['Hero heading','Fresh, wholesome juice squeezed daily in Accra','text'],
                'hero_body'=>['Hero body','Jay fooDs presses premium, 100% natural juices from local sweet oranges, watermelon, pineapple and wholesome blends — no added sugar, no preservatives. Order a single bottle or a bulk case for your shop, office or event.','textarea'],
            ],
            'features' => [
                'why_eyebrow'=>['Section eyebrow','Why Accra loves Jay fooDs','text'], 'why_heading'=>['Heading','Uncompromising freshness in every bottle','text'], 'why_body'=>['Body',"Built on simple, healthy, high-quality refreshment. Here's what makes ordering from us effortless.",'textarea'],
                'feature_1_heading'=>['Feature 1 heading','Fresh taste','text'], 'feature_1_body'=>['Feature 1 body','Pressed daily from high-quality Ghanaian farms to lock in flavour and nutrients — never from concentrate.','textarea'],
                'feature_2_heading'=>['Feature 2 heading','Simple ordering','text'], 'feature_2_body'=>['Feature 2 body','Pick your juices, add your details, and place the order in under a minute. No accounts, no friction.','textarea'],
                'feature_3_heading'=>['Feature 3 heading','Easy payment','text'], 'feature_3_body'=>['Feature 3 body','Mobile Money and card payments are processed securely through Paystack.','textarea'],
                'feature_4_heading'=>['Feature 4 heading','Reliable delivery','text'], 'feature_4_body'=>['Feature 4 body','Prompt distribution to homes, offices, shops, supermarkets and events across Greater Accra.','textarea'],
            ],
            'menu' => ['menu_eyebrow'=>['Eyebrow','Explore our selections','text'],'menu_heading'=>['Heading','Freshness squeezed to perfection','text'],'menu_body'=>['Body','No preservatives, artificial sweeteners or colourings — just pure, wholesome fruit juice bottled and sealed in Accra.','textarea']],
            'ordering' => [
                'how_eyebrow'=>['Eyebrow','Community simplicity','text'],'how_heading'=>['Heading','How simple ordering is','text'],'how_body'=>['Body','We value your time — quick, direct and built around your day.','textarea'],
                'step_1_heading'=>['Step 1 heading','Choose your juice','text'],'step_1_body'=>['Step 1 body','Browse our premium catalogue and pick the juices and bottle sizes you love.','textarea'],
                'step_2_heading'=>['Step 2 heading','Add to cart','text'],'step_2_body'=>['Step 2 body','Choose quantities, add different products to your cart, and enter your delivery details.','textarea'],
                'step_3_heading'=>['Step 3 heading','Pay & receive','text'],'step_3_body'=>['Step 3 body','Pay securely through Paystack, then relax while we deliver fresh to your door.','textarea'],
            ],
            'bulk' => [
                'bulk_eyebrow'=>['Eyebrow','Distribution & B2B partnerships','text'],'bulk_heading'=>['Heading','Need Jay fooDs juice in bulk?','text'],'bulk_body'=>['Body','We supply premium fresh juice for retail shops, event planners, offices, schools, churches and hotels looking for a reliable beverage partner in Accra.','textarea'],
                'bulk_1_heading'=>['Card 1 heading','Catering & private events','text'],'bulk_1_body'=>['Card 1 body','Weddings, funerals, birthdays and anniversaries. Bulk cases and dispensers keep your guests fully refreshed.','textarea'],
                'bulk_2_heading'=>['Card 2 heading','Retailers & supermarkets','text'],'bulk_2_body'=>['Card 2 body','Convenience stores, minimarts and supermarkets across Accra. Durable cases with clear expiry stamps.','textarea'],
                'bulk_3_heading'=>['Card 3 heading','Churches, schools & offices','text'],'bulk_3_body'=>['Card 3 body','Wholesome morning refreshments delivered on an accurate weekly schedule.','textarea'],
            ],
            'about' => [
                'about_eyebrow'=>['Eyebrow','About Jay fooDs','text'],'about_heading'=>['Heading','Sustaining Accra with fresh, wholesome fruit drinks','text'],'about_body'=>['Body','Jay fooDs is a fruit-juice company based in Accra, Ghana, focused on refreshing products you can enjoy at home, work, school, events and business locations.','textarea'],
                'about_1_heading'=>['Point 1 heading','Hygienic production','text'],'about_1_body'=>['Point 1 body','Bottled and sealed under strict hygiene protocols in Accra.','textarea'],'about_2_heading'=>['Point 2 heading','100% Ghana farms','text'],'about_2_body'=>['Point 2 body','Supporting local growers with fresh, seasonal fruit.','textarea'],'about_3_heading'=>['Point 3 heading','Prompt deliveries','text'],'about_3_body'=>['Point 3 body','Connecting families, caterers, schools and offices daily.','textarea'],'about_4_heading'=>['Point 4 heading','Community pride','text'],'about_4_body'=>['Point 4 body','A local team delivering genuine goodness and convenience.','textarea'],
            ],
            'reviews' => [
                'reviews_eyebrow'=>['Eyebrow','Real testimonials','text'],'reviews_heading'=>['Heading','Loved by our customers','text'],'reviews_body'=>['Body','From local business owners to event organisers and daily juice lovers across Accra.','textarea'],
                'review_1_quote'=>['Review 1','Your delivery process is simple and I really love the payment method. Thanks!','textarea'],'review_1_name'=>['Reviewer 1','Nathan Fritz','text'],'review_2_quote'=>['Review 2','Great job guys, keep it up. The mango is my favourite.','textarea'],'review_2_name'=>['Reviewer 2','Duku Prince','text'],'review_3_quote'=>['Review 3','Nice juice and always delivered fresh to the shop on time.','textarea'],'review_3_name'=>['Reviewer 3','Tetteh','text'],
            ],
            'faq' => [
                'faq_eyebrow'=>['Eyebrow','Have questions? We have answers','text'],'faq_heading'=>['Heading','Frequently asked questions','text'],
                'faq_1_question'=>['Question 1','How long does delivery take inside Accra?','text'],'faq_1_answer'=>['Answer 1',"For standard orders placed before 10:00 AM, we offer same-day delivery. Orders after 10:00 AM are scheduled for the next morning.",'textarea'],'faq_2_question'=>['Question 2','How can I track my order?','text'],'faq_2_answer'=>['Answer 2','Every order gets a reference code at checkout. Keep it handy for delivery updates.','textarea'],'faq_3_question'=>['Question 3','What payment methods do you accept?','text'],'faq_3_answer'=>['Answer 3','Secure payments are processed through Paystack.','textarea'],'faq_4_question'=>['Question 4','Are the juices natural, and how long do they keep?','text'],'faq_4_answer'=>['Answer 4','Yes — 100% natural with no added sweeteners, colourings or chemical preservatives. Keep refrigerated and follow the printed shelf life.','textarea'],'faq_5_question'=>['Question 5','Do you offer special rates for shops, gyms and schools?','text'],'faq_5_answer'=>['Answer 5',"Absolutely. Bulk orders unlock tiered per-bottle pricing when you reach a product's minimum quantity.",'textarea'],
            ],
            'contact' => [
                'contact_eyebrow'=>['Eyebrow','Find our office','text'],'contact_heading'=>['Heading','Get in touch with Jay fooDs','text'],'contact_body'=>['Body',"Interested in wholesale supply, event dispensers, or a retail order? Reach out and we'll respond quickly.",'textarea'],
                'headquarters_label'=>['Headquarters label','Headquarters','text'],'headquarters_name'=>['Headquarters name','Jay fooDs Ghana','text'],'contact_address'=>['Headquarters address','Accra, Ghana — delivery across the Greater Accra region.','textarea'],
                'hotline_label'=>['Hotline label','Direct hotline','text'],'telephone_number'=>['Telephone link number','+233246328461','tel'],'telephone_display'=>['Displayed telephone','0246 328 461','text'],'whatsapp_number'=>['WhatsApp number','233246328461','tel'],'whatsapp_link_text'=>['WhatsApp link text','Chat on WhatsApp →','text'],
                'opening_label'=>['Opening-hours label','Opening hours','text'],'opening_days'=>['Opening days','Monday to Sunday','text'],'opening_hours'=>['Opening-hours text','8:30am – 6:00pm, including weekends.','text'],
            ],
            'footer' => [
                'footer_tagline'=>['Tagline','Pure Refreshment, squeezed to perfection.','text'],'footer_body'=>['Body','Fresh, 100% natural fruit juice squeezed daily in Accra, Ghana. Delivered to homes, offices, shops and events across the Greater Accra region.','textarea'],
                'facebook_url'=>['Facebook URL','#','url'],'instagram_url'=>['Instagram URL','#','url'],'footer_copyright'=>['Copyright','Jay fooDs Ghana. All rights reserved. Made with 🧡 in Accra.','text'],
            ],
        ];
    }

    public function publicContent(): void { Response::json(['data'=>$this->values()]); }
    public function adminContent(): void { Response::json(['data'=>['groups'=>self::definitions(),'values'=>$this->values()]]); }
    public function update(): void
    {
        $in=json_decode((string)file_get_contents('php://input'),true); $in=is_array($in)?$in:[]; $allowed=[];
        foreach(self::definitions() as $fields)foreach($fields as $key=>$meta)$allowed[$key]=$meta;
        $stmt=$this->db->prepare("INSERT INTO site_content(content_key,value,updated_at) VALUES(:key,:value,datetime('now')) ON CONFLICT(content_key) DO UPDATE SET value=excluded.value,updated_at=datetime('now')");
        foreach($in as $key=>$value)if(isset($allowed[$key]))$stmt->execute([':key'=>$key,':value'=>trim((string)$value)]);
        Response::json(['data'=>$this->values()]);
    }
    private function values(): array
    {
        $values=[];foreach(self::definitions() as $fields)foreach($fields as $key=>$meta)$values[$key]=$meta[1];
        foreach($this->db->query('SELECT content_key,value FROM site_content')->fetchAll() as $r)if(array_key_exists($r['content_key'],$values))$values[$r['content_key']]=$r['value'];
        return $values;
    }
}
