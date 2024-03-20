<?php

namespace Datto\Util;

use SimpleXMLElement;

/**
 * SimpleXMLElement Helpers
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class XmlUtil
{
    /**
     * Helper method for simplifying reading attributes on SimpleXMLElement objects
     *
     * @param SimpleXMLElement $node
     * @param string $attributeName Desired attribute
     * @return null|string
     */
    public static function getAttribute(SimpleXMLElement $node, string $attributeName)
    {
        return isset($node[$attributeName]) ? (string)$node[$attributeName] : null;
    }

    /**
     * A work around for SimpleXmlElement limitation where it's not able
     * to add other SimpleXmlElements as its children...
     *
     * @param SimpleXmlElement $parent
     * @param SimpleXmlElement $child
     */
    public static function addChildXml(SimpleXmlElement $parent, SimpleXmlElement $child)
    {
        $addedChild = $parent->addChild($child->getName(), (string)$child);
        foreach ($child->attributes() as $name => $value) {
            $addedChild->addAttribute($name, $value);
        }

        foreach ($child->children() as $grandChild) { // :-)
            XmlUtil::addChildXml($addedChild, $grandChild);
        }
    }
}
