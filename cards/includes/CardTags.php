<?php
/**
 * Card tags.
 *
 * Module-local until core tagging (web2project/web2project#529) exists; the
 * API and rules follow that issue so cards can move to the core tables:
 * one shared vocabulary, uniqueness by slug, and the first spelling used is
 * the display name.
 *
 * @package web2project\modules\cards
 */
class CCardTags
{
    const MAX_LENGTH = 64;
    const MAX_PER_CARD = 20;

    /**
     * Lower-cased, trimmed, inner whitespace collapsed.
     *
     * @param string $name
     *
     * @return string
     */
    public static function slug($name)
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $name));

        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    /**
     * Tags for many cards in one query.
     *
     * @param int[] $cardIds
     *
     * @return array<int, int[]> card_id => tag ids
     */
    public static function forCards(array $cardIds)
    {
        $ids = array_filter(array_map('intval', $cardIds));
        $result = array();
        if (!$ids) {
            return $result;
        }
        $q = new w2p_Database_Query();
        $q->addTable('card_tag_links');
        $q->addQuery('link_card, link_tag');
        $q->addWhere('link_card IN (' . implode(',', $ids) . ')');
        foreach ($q->loadList() as $row) {
            $result[(int) $row['link_card']][] = (int) $row['link_tag'];
        }

        return $result;
    }

    /**
     * Tag names by id.
     *
     * @param int[] $tagIds
     *
     * @return array<int, string>
     */
    public static function names(array $tagIds)
    {
        $ids = array_unique(array_filter(array_map('intval', $tagIds)));
        if (!$ids) {
            return array();
        }
        $q = new w2p_Database_Query();
        $q->addTable('card_tags');
        $q->addQuery('card_tag_id, card_tag_name');
        $q->addWhere('card_tag_id IN (' . implode(',', $ids) . ')');
        $q->addOrder('card_tag_name');

        return $q->loadHashList();
    }

    /**
     * Every tag name, for autocomplete.
     *
     * @return array<int, string>
     */
    public static function all()
    {
        $q = new w2p_Database_Query();
        $q->addTable('card_tags');
        $q->addQuery('card_tag_id, card_tag_name');
        $q->addOrder('card_tag_name');

        return $q->loadHashList();
    }

    /**
     * The id of a tag, creating it if needed.
     *
     * @param string $name
     *
     * @return int 0 if the name is empty or too long
     */
    public static function idFor($name)
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $name));
        $slug = self::slug($name);
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ('' === $slug || $length > self::MAX_LENGTH) {
            return 0;
        }

        $q = new w2p_Database_Query();
        $q->addTable('card_tags');
        $q->addQuery('card_tag_id');
        $q->addWhere('card_tag_slug = ' . $q->quote($slug));
        $id = (int) $q->loadResult();
        if ($id) {
            return $id;
        }

        $q = new w2p_Database_Query();
        $q->addTable('card_tags');
        $q->addInsert('card_tag_name', $name);
        $q->addInsert('card_tag_slug', $slug);
        $q->exec();

        // Read it back by slug: another request may have created it first.
        $q = new w2p_Database_Query();
        $q->addTable('card_tags');
        $q->addQuery('card_tag_id');
        $q->addWhere('card_tag_slug = ' . $q->quote($slug));

        return (int) $q->loadResult();
    }

    /**
     * @param int $cardId
     * @param int $tagId
     *
     * @return bool false if the card already has it or is at the limit
     */
    public static function attach($cardId, $tagId)
    {
        $current = self::forCards(array($cardId));
        $current = isset($current[$cardId]) ? $current[$cardId] : array();
        if (in_array((int) $tagId, $current, true) || count($current) >= self::MAX_PER_CARD) {
            return false;
        }
        $q = new w2p_Database_Query();
        $q->addTable('card_tag_links');
        $q->addInsert('link_card', (int) $cardId);
        $q->addInsert('link_tag', (int) $tagId);

        return (bool) $q->exec();
    }

    /**
     * @param int $cardId
     * @param int $tagId
     *
     * @return bool false if the card didn't have it
     */
    public static function detach($cardId, $tagId)
    {
        $current = self::forCards(array($cardId));
        if (!isset($current[$cardId]) || !in_array((int) $tagId, $current[$cardId], true)) {
            return false;
        }
        $q = new w2p_Database_Query();
        $q->setDelete('card_tag_links');
        $q->addWhere('link_card = ' . (int) $cardId . ' AND link_tag = ' . (int) $tagId);

        return (bool) $q->exec();
    }

    /**
     * Removes all tag links of deleted cards. Tags themselves are kept.
     *
     * @param int[] $cardIds
     */
    public static function deleteFor(array $cardIds)
    {
        $ids = array_filter(array_map('intval', $cardIds));
        if (!$ids) {
            return;
        }
        $q = new w2p_Database_Query();
        $q->setDelete('card_tag_links');
        $q->addWhere('link_card IN (' . implode(',', $ids) . ')');
        $q->exec();
    }
}
