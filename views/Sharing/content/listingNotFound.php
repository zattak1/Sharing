<?php $text = Q_Text::get('Sharing/content'); ?>
<div class="Sharing_listing Sharing_listing_notFound">
	<p><?php echo Q_Html::text($text['listing']['NotFound']) ?></p>
	<a class="Sharing_back" href="<?php echo Q_Html::text(Sharing::url('sharing')) ?>">&larr; <?php echo Q_Html::text($text['listing']['Back']) ?></a>
</div>
