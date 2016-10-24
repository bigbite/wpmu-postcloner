<?php
$siteList = [];
foreach ( $sites as $site ) {
	$siteList[] = sprintf( '<option value="%1$s">%2$s</option>', $site->blog_id, $site->blogname );
}
?>

<div>
	<select name="sitelist" style="float:left"><?php echo implode( '', $siteList ); ?></select>
	<input id="clone-post" class="button button-primary button-large" name="clone" type="submit" value="Clone" style="float:right">
	<div class="clear"></div>
</div>
<hr style="margin:10px 0">
