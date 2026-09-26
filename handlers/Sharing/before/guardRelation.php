<?php
/**
 * Streams/relateTo, relateFrom, unrelateTo and unrelateFrom for every
 * Sharing type {before}: relations to and from the plugin's streams are the
 * plugin's own (ro#586). See Sharing_Guard.
 * @event Streams/relateTo/Sharing/listing {before}
 * @param {array} $params
 */
function Sharing_before_guardRelation($params)
{
	Sharing_Guard::beforeRelation($params);
}
