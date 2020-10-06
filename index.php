<?php
global $module;
/**
* @var $module \uzgent\DataQualityExternalModule\DataQualityExternalModule
*/

?>
<H1>
  Export or Import Data Quality
</H1>
<p>
  Press download to export data resolution workflow data

  <BR/>
  <form enctype="multipart/form-data" action="<?php echo $module->getProcessJsonDownURL(); ?>" method="post">
    <input name="pid" type="hidden" value="<?php echo $_GET['pid'];?>"/>
    <input class="btn btn-primary" type="submit" value="download"/>
  </form>
</p>

<BR/>

<p>
  <?php if ($_GET["imported"] === "1"): ?>
      <div class="green">Imported</div>
      <br/>
  <?php endif; ?>
  <form enctype="multipart/form-data" method="post" action="<?php echo $module->getProcessJsonUpURL(); ?>" enctype="multipart/form-data" style="border: 1px solid black; padding: 10px">
    <input name="pid" type="hidden" value="<?php echo $_GET['pid'];?>"/>
    <input id="ignore" name="ignore" type="checkbox"/><label for="ignore">&nbsp;Ignore PID and Usernames?</label><BR/><BR/>
    <p>Select CSV to upload:</p>
    <input type="file" name="import_file">
    <input class="btn btn-primary" type="submit" value="upload">
  </form>
</p>

<BR/>


<?php
