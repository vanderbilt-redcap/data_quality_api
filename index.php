<?php
global $module;
/**
* @var $module \uzgent\DataQualityExternalModule\DataQualityExternalModule
*/

?>
<H1>
  Data Quality API
</H1>
<p>
  <BR/>
  <form enctype="multipart/form-data" action="<?php echo $module->getProcessJsonDownURL(); ?>" method="post" style="border: 1px solid black; padding: 10px">
    <h3>Export</h3>
    <input name="pid" type="hidden" value="<?php echo $_GET['pid'];?>"/>
    <input class="btn btn-primary" type="submit" value="Download"/>
  </form>
</p>

<BR/>

<p>
  <?php if ($_GET["imported"] === "1"): ?>
      <div class="green">Imported</div>
      <br/>
  <?php endif; ?>
  <form enctype="multipart/form-data" method="post" action="<?php echo $module->getProcessJsonUpURL(); ?>" enctype="multipart/form-data" style="border: 1px solid black; padding: 10px">
    <h3>Import</h3>
    <input name="pid" type="hidden" value="<?php echo $_GET['pid'];?>"/>
    <input id="ignore" name="ignore" type="checkbox"/><label for="ignore">&nbsp;Ignore PID and Usernames? (use this if syncing across projects with different PIDs)</label><BR/><BR/>
    <input id="file_repo" name="file_repo" type="checkbox"/><label for="file_repo">&nbsp;Save a log file of this action to the project's file repository?</label><BR/><BR/>
    <p>Select JSON file to upload:</p>
    <input type="file" name="import_file">
    <input class="btn btn-primary" type="submit" value="Upload">
  </form>
</p>

<BR/>


<?php
