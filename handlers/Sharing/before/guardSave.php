<?php
/**
 * Streams/Stream/save/<every Sharing type> {before}: a Sharing stream is
 * created, and has its protected fields changed, only by the plugin itself
 * (ro#586). See Sharing_Guard.
 * @event Streams/Stream/save/Sharing/listing {before}
 * @param {array} $params
 * @param {Streams_Stream} $params.stream
 * @param {array} $params.modifiedFields
 */
function Sharing_before_guardSave($params)
{
	Sharing_Guard::beforeSave($params);
}
