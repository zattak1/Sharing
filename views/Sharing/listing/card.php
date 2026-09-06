<?php
/**
 * One listing card. Variables: $listing (Sharing_Listing::export), $text.
 */
$t = $text['listing'];
$url = $listing['url'];
$direction = $listing['direction'];
$kind = $listing['kind'];
?>
<a class="Sharing_listing_card Sharing_direction_<?php echo $direction ?> Sharing_kind_<?php echo $kind ?>"
   href="<?php echo Q_Html::text($url) ?>"
   data-publisherId="<?php echo Q_Html::text($listing['publisherId']) ?>"
   data-streamName="<?php echo Q_Html::text($listing['name']) ?>">
	<span class="Sharing_listing_badges">
		<span class="Sharing_badge Sharing_badge_direction"><?php echo Q_Html::text($t['direction'][$direction]) ?></span>
		<span class="Sharing_badge Sharing_badge_kind"><?php echo Q_Html::text($t['kind'][$kind]) ?></span>
	</span>
	<span class="Sharing_listing_title"><?php echo Q_Html::text($listing['title']) ?></span>
	<?php if ($listing['area'] !== ''): ?>
		<span class="Sharing_listing_area"><?php echo Q_Html::text($listing['area']) ?></span>
	<?php endif ?>
	<span class="Sharing_listing_publisher">
		<?php echo Q::tool('Users/avatar', array(
			'userId' => $listing['publisherId'], 'icon' => 40, 'short' => true
		), array('id' => 'listing_' . md5($listing['publisherId'] . $listing['name']))) ?>
	</span>
</a>
