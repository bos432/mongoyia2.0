<?php

use yii\db\Migration;

class m260608_190000_mongoyia_focused_translations extends Migration
{
    private $nextIdOffset = 0;

    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%base_lang}}', true) === null) {
            echo "Table {{%base_lang}} not found, skip.\n";
            return true;
        }

        foreach ($this->rows() as $row) {
            $this->upsertLang($row);
        }

        return true;
    }

    public function safeDown()
    {
        echo "Focused translation baseline migration is non-destructive; no rows are removed.\n";
        return true;
    }

    private function upsertLang(array $row)
    {
        $exists = (new \yii\db\Query())
            ->from('{{%base_lang}}')
            ->where($this->condition($row))
            ->exists($this->db);

        $values = [
            'store_id' => $row['store_id'],
            'name' => $row['name'],
            'source' => 'zh-CN',
            'target' => $row['target'],
            'table_code' => $row['table_code'],
            'target_id' => $row['target_id'],
            'content' => $row['content'],
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'updated_at' => time(),
            'updated_by' => 5,
        ];

        if ($exists) {
            $this->update('{{%base_lang}}', $values, $this->condition($row));
            return;
        }

        $values['id'] = $this->nextLangId();
        $values['created_at'] = time();
        $values['created_by'] = 5;
        $this->insert('{{%base_lang}}', $values);
    }

    private function condition(array $row)
    {
        return [
            'store_id' => $row['store_id'],
            'table_code' => $row['table_code'],
            'target_id' => $row['target_id'],
            'name' => $row['name'],
            'target' => $row['target'],
        ];
    }

    private function nextLangId()
    {
        $maxId = (int)(new \yii\db\Query())->from('{{%base_lang}}')->max('id', $this->db);
        $this->nextIdOffset++;
        return $maxId + $this->nextIdOffset;
    }

    private function rows()
    {
        return [
            [
                'store_id' => 13,
                'table_code' => 2400,
                'target_id' => 90,
                'name' => 'name',
                'target' => 'en',
                'content' => 'pink eyebrow pencil',
            ],
            [
                'store_id' => 13,
                'table_code' => 2400,
                'target_id' => 90,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'ягаан хөмсөгний харандаа',
            ],
            [
                'store_id' => 9,
                'table_code' => 2400,
                'target_id' => 102,
                'name' => 'name',
                'target' => 'en',
                'content' => "women's skirt",
            ],
            [
                'store_id' => 9,
                'table_code' => 2400,
                'target_id' => 102,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'эмэгтэйчүүдийн банзал',
            ],
            [
                'store_id' => 9,
                'table_code' => 2400,
                'target_id' => 102,
                'name' => 'brief',
                'target' => 'en',
                'content' => 'Skirt refers to clothing that is worn below the waist without trouser legs. It is composed of a skirt waist and a skirt body. It is the earliest form of clothing for humans [1]. It is widely accepted by people because of its good ventilation and heat dissipation performance, easy wearing, freedom of movement, beautiful styles and diverse changes. Broadly covers styles such as dresses, skirts, and culottes. According to the shape, it can be divided into straight skirts, umbrella skirts, and fishtail skirts. According to the material, it includes denim skirts, chiffon skirts, and lace skirts. According to the use, it can be divided into school uniform skirts, professional skirts, evening dresses, etc. The visual effect can be adjusted through color selection, and waistline designs such as low-waist skirts and high-waist skirts can adjust body proportions; mini skirts can be paired with boots or pumps, and pleated skirts are suitable for matching shoes, Mary Jane shoes, etc. [5-6]. In daily life, they are mainly worn by women, while retaining men\'s designs to meet different needs.',
            ],
            [
                'store_id' => 9,
                'table_code' => 2400,
                'target_id' => 102,
                'name' => 'brief',
                'target' => 'mn',
                'content' => 'Юбка гэдэг нь өмдний хөлгүй бэлхүүсээс доош өмсдөг хувцасыг хэлдэг. Энэ нь банзал бэлхүүс, банзал их биеээс бүрдэнэ. Энэ нь хүний ​​хувцасны хамгийн эртний хэлбэр юм [1]. Агааржуулалт сайтай, дулаан ялгаруулах чадвар сайтай, өмсөхөд хялбар, хөдөлгөөний эрх чөлөө, гоёмсог загвар, олон янзын өөрчлөлтүүдээрээ хүмүүс үүнийг өргөнөөр хүлээн зөвшөөрдөг. Хувцаслалт, банзал, юбка зэрэг хэв маягийг өргөнөөр хамарна. Хэлбэрээр нь шулуун юбка, шүхэр хормой, загасны гэзэгтэй юбка гэж хуваагдана. Материалын дагуу жинсэн банзал, номын хавтасны банзал, нэхсэн тор банзал зэргийг багтаасан болно. Хэрэглээний дагуу сурагчийн дүрэмт хувцасны банзал, мэргэжлийн банзал, үдшийн даашинз гэх мэтээр хувааж болно. Харааны эффектийг өнгө сонгох замаар тохируулах боломжтой бөгөөд бэлхүүс багатай банзал, өндөр бэлхүүстэй банзал зэрэг бэлхүүсний загвар нь биеийн харьцааг тохируулах боломжтой; мини юбка нь гутал, шахуургатай хослуулж болох ба хуниастай банзал нь тохирох гутал, Мэри Жэйн гутал зэрэгт тохиромжтой [5-6]. Өдөр тутмын амьдралдаа эмэгтэйчүүд ихэвчлэн өмсдөг бол эрэгтэй хүний ​​​​загварыг өөр өөр хэрэгцээнд нийцүүлэн хадгалдаг.',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 94,
                'name' => 'name',
                'target' => 'en',
                'content' => "women's clothing",
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 94,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'эмэгтэйчүүдийн хувцас',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 106,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Powder Pencil',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 106,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Нунтаг харандаа',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 93,
                'name' => 'name',
                'target' => 'en',
                'content' => "Men's Clothing",
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 93,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Эрэгтэй хувцас',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 95,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Electronics',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 95,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Цахилгаан бараа',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 96,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Shoes',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 96,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Гутал',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 97,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Cosmetics',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 97,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Гоо сайхны бүтээгдэхүүн',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 100,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Sportswear',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 100,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Спортын хувцас',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 101,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Sportswear',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 101,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Спортын хувцас',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 102,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Suits',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 102,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Костюм',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 103,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Suits',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 103,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Костюм',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 104,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Watches',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 104,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Бугуйн цаг',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 105,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Earphones',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 105,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Чихэвч',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 107,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Eyebrow Pencils',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 107,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Хөмсөгний харандаа',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 108,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Sneakers',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 108,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Пүүз',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 109,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Daily Necessities',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 109,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Өдөр тутмын хэрэгцээт зүйлс',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 110,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Kitchen Supplies',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 110,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Гал тогооны хэрэгсэл',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 111,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Household Items',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 111,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Гэр ахуйн эд зүйлс',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 112,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Slippers',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 112,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Шаахай',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 113,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Leather Shoes',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 113,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Арьсан гутал',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 114,
                'name' => 'name',
                'target' => 'en',
                'content' => 'Casual Shoes',
            ],
            [
                'store_id' => 5,
                'table_code' => 2480,
                'target_id' => 114,
                'name' => 'name',
                'target' => 'mn',
                'content' => 'Энгийн гутал',
            ],
        ];
    }
}
