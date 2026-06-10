<?php
/**
 * REDCap External Module: Manage Default Survey Settings
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ManageSurveyDefaults;

require_once 'SurveyTheme.php';
use ExternalModules\AbstractExternalModule;
use RCView;
use MCRI\ManageSurveyDefaults\SurveyTheme as ManageSurveyDefaultsSurveyTheme;
use Throwable;
use Vanderbilt\REDCap\Tests\Survey\SurveyTest;

class ManageSurveyDefaults extends AbstractExternalModule
{
    static $NonEmptySystemDefaultSettingValues = array(
        'enhanced_choices' => '0', // Standard
        'text_size' => '1', // Large
        'font_family' => '16', // Open Sans
        'question_auto_numbering' => '1', // auto
        'question_by_section' => '0' // single page
    );

    public function redcap_module_system_enable($version)
    {
        $this->overrideEmptyDefaultSettings(); // set values where system defaults are not the "empty" choice value
    }


    /**
     * redcap_module_configuration_settings()
     * Triggered when the system or project configuration dialog is displayed.
     * Populate the theme dropdown choices.
     * @param string $project_id, $settings
     */
    public function redcap_module_configuration_settings($project_id, $settings) {
        if (intval($project_id)) return $settings; // return project-level settings unaltered
        
        foreach ($settings as &$setting) {
            $setting['name'] = $this->interpolateLanguageElements($setting['name']); 

            if ($setting['key']=='font_family') {
                $setting['choices'] = $this->makeFontChoices();

            } else if ($setting['key']=='theme') {
                $setting['choices'] = $this->makeThemeChoices();
                
            } else if ($setting['key']=='manage_global_themes') {
                $url = $this->getUrl('manage_global_themes.php',false,false);
                $setting['name'] = str_replace('href="#"', 'href="'.$url.'"', $setting['name']);

            } else if ($setting['key']=='global-theme-hidden') {
                $setting['choices'] = $this->makeThemeChoices('0');
            
            } else if ($setting['key']=='global-theme-editable') {
                $setting['choices'] = $this->makeThemeChoices('0');
            
            } else if (array_key_exists('choices', $setting)) {
                foreach ($setting['choices'] as &$choice) {
                    $choice['name'] = $this->interpolateLanguageElements($choice['name']);
                }
            }
        }
        return $settings;
    }

    public function redcap_every_page_top($project_id=null) 
    {
        if (!defined('PAGE' )) return;
        switch (PAGE) {
            case 'Surveys/create_survey.php': $selectDefaults = 1; break;
            case 'Surveys/edit_info.php': $selectDefaults = 0; break;
            default: return;
        }
        $config = $this->getConfig();
        $settings = array();
        foreach ($config['system-settings'] as $ss) {
            if ($ss['type']==='descriptive') continue;
            $value = $this->getSystemSetting($ss['key']);
            if ($ss['key']==='font_family' && $value==-1) {
                // Arial option has no value; coded in system settings as -1 (0='Arial black')
                $settings[$ss['key']] = '';
            } else {
                $settings[$ss['key']] = $this->escape($value ?? '');
            }
        }
        $this->initializeJavascriptModuleObject();
?>
<script type="text/javascript">
    /* External Module: Manage Default Survey Settings */
    (() => {
        let module = <?=$this->getJavascriptModuleObjectName()?>;
        module.system_defaults = JSON.parse('<?php echo json_encode($settings); ?>');
        module.select_defaults = <?=intval($selectDefaults)?>;
        module.init = function() {
            if (module.select_defaults) {
                // create_survey.php: select default choices according to system settings
                for (const setting in module.system_defaults) {
                    let ctrl = $('[name='+setting+']:first');
                    let system_default = module.system_defaults[setting];
                    if ($(ctrl).length && $(ctrl).val()!=system_default) {
                        $(ctrl).val(system_default).trigger('change');
                        console.log(`Set ${setting} to '${system_default}'`);
                    }
                }
            }
            // create_survey.php/edit_info.php hide hidden themes (unless selected)
            let selectedTheme = $('#theme').val();
            if (module.system_defaults['global-theme-hidden'].length) {
                module.system_defaults['global-theme-hidden'].forEach(hiddenTheme => {
                    if (hiddenTheme!==null && hiddenTheme!=selectedTheme) {
                        if (hiddenTheme==0) {
                            $('#theme option:first').remove(); // Default (value attr has no value)
                        } else {
                            $("#theme option[value='"+hiddenTheme+"']").remove();
                        }
                        console.log(`Remove theme option ${hiddenTheme}`);
                    }
                });
            }
        }
        $(document).ready(function(){
            module.init();
        });
    })()
</script>
<?php
    }

    /**
     * Where the built-in default option for a setting is not the empty value option, 
     * set the module default so admin user sees correct initial values in module config settings dialog
     */
    protected function overrideEmptyDefaultSettings() 
    {
        if (sizeof(static::$NonEmptySystemDefaultSettingValues)==0) return;
        foreach (static::$NonEmptySystemDefaultSettingValues as $setting => $defaultValue) {
            $currentValue = $this->getSystemSetting($setting);
            if (is_null($currentValue)) {
                $this->setSystemSetting($setting, $defaultValue);
            }
        }
    }

    /**
     * Replace placeholder text in configuration settings labels
     */
    protected function interpolateLanguageElements($string='') 
    {
        do {
            $matches = array();
            if (preg_match('/^(.*)\[\[lang=(\w+_\d+):(.*)\]\](.*)$/s', $string, $matches)) {
                $start = $matches[1];                            // <div class="header" style="width:720px">
                $langElementRef = $matches[2];                   // survey_1011
                $langElementTxt = RCView::tt($langElementRef);  // Basic Survey Settings
                $end = $matches[4];                              // </div>
                $string = $start.$langElementTxt.$end;
            }
        } while (sizeof($matches)); // repeat until no more lang matches 

        return $string;
    }

    /**
     * Get choices for Font system setting dropdown list
     */
    protected function makeFontChoices()
    {
        $fonts = \Survey::getFonts();
        $choices = array(array('value'=>-1,'name'=>'Arial')); // Arial not returned by \Survey::getFonts(); can't use empty key as gets removed from settings returned to config dialog, 0 used for Arial Black
        foreach ($fonts as $key => $label) {
            $labelParts = explode(',',$label);
            $label = str_replace("'",'',$labelParts[0]);
            $choices[] = array('value'=>$key,'name'=>$label);
        }
        return $choices;
    }

    /**
     * Get choices for Theme system setting dropdown list
     */
    protected function makeThemeChoices($valueForDefaultChoice='')
    {
        $themes = \Survey::getThemes(null, false, false);
        $choices = array(array('value'=>$valueForDefaultChoice,'name'=>RCView::tt('survey_1017',false)));
        foreach ($themes as $key => $label) {
            $choices[] = array('value'=>$key,'name'=>$label);
        }
        return $choices;
    }

    /**
     * Page content for managing global survey themes
     */
    public function manageGlobalThemesPage()
    {
        global $lang;
        if (!array_key_exists('econsent_02', $lang)) $lang['econsent_02']; // Active? (for pre v14.5)
        $tableId = 'themes';
        $this->initializeJavascriptModuleObject();
        ?>
        <h4 style="margin-top:0;" class="clearfix"><div class="pull-left float-left"><i class="fas fa-wrench mr-1"></i><?=RCView::tt("survey_1038")?></div></h4>
        <p>Manage your globally available survey themes.</p>
        <table id="<?=$tableId?>" class="display" cellspacing="0" width="100%">
            <thead>
                <tr>
                    <th colspan="3"></th>
                    <th colspan="2"><?=RCView::tt('control_center_360')/*General*/?></th>
                    <th colspan="2"><?=RCView::tt('survey_1025')/*General*/?></th>
                    <th colspan="2"><?=RCView::tt('survey_1027')/*Section headers*/?></th>
                    <th colspan="2"><?=RCView::tt('survey_1026')/*Survey questions*/?> </th>
                </tr>
                <tr>
                    <th></th>
                    <th class="text-left"><?=RCView::tt('survey_1016')/*Survey theme*/?></th>
                    <th><?=RCView::tt('econsent_02')/*Active?*/?></th>
                    <th><?=RCView::tt('survey_1028')/*Page Background*/?></th>
                    <th><?=RCView::tt('survey_1029')/*Button Text*/?></th>
                    <th><?=RCView::tt('folders_08')/*Title/Instruction Text*/?></th>
                    <th><?=RCView::tt('folders_09')/*Title Background*/?></th>
                    <th><?=RCView::tt('folders_08')/*Header Text*/?></th>
                    <th><?=RCView::tt('folders_09')/*Header Background*/?></th>
                    <th><?=RCView::tt('folders_08')/*Question Text*/?></th>
                    <th><?=RCView::tt('folders_09')/*Question Background*/?></th>
                </tr>
            </thead>
        </table>
        <p><button type="button" class="edit-theme btn btn-xs btn-success" style="color:#fff" onclick="<?=$this->getJavascriptModuleObjectName()?>.editTheme(null)"><i class="fas fa-plus mr-1"></i><?=RCView::tt('data_entry_247')?></button></p>
        <div id="theme-properties">
<?php
		// Custom theme spectrum widgets
		$custom_theme_opts = RCView::table(array('style'=>'margin:3px 0;padding:6px 0;width:100%;border-bottom:1px dashed #ccc;border-top:1px dashed #ccc;', 'cellpadding'=>0, 'cellspacing'=>0),
								RCView::tr(array(),
									// Page bg and button text
									RCView::td(array('style'=>'padding:3px 0 5px 22px;border-right:1px solid #ccc;'),
										RCView::div(array('style'=>'font-weight:bold;margin-bottom:5px;'), RCView::tt('survey_1030')) .
										RCView::div(array('style'=>'float:left;width:110px;padding-top:8px;'), RCView::tt('survey_1028')) .
										RCView::div(array('style'=>'float:left;'), RCView::input(array('name'=>'theme_bg_page', 'type'=>'text', 'class'=>'theme-property', 'size'=>4))) .
										RCView::div(array('style'=>'clear:both;float:left;width:110px;padding-top:10px;'), RCView::tt('survey_1029')) .
										RCView::div(array('style'=>'float:left;padding-top:2px;'), RCView::input(array('name'=>'theme_text_buttons', 'type'=>'text', 'class'=>'theme-property', 'size'=>4)))
									) .
									// Title & instructions
									RCView::td(array('style'=>'padding:3px 0 5px 8px;border-right:1px solid #ccc;'),
										RCView::div(array('style'=>'font-weight:bold;margin-bottom:5px;'), RCView::tt('survey_1025')) .
										RCView::div(array('style'=>'float:left;width:110px;padding-top:8px;'), RCView::tt('folders_08')) .
										RCView::div(array('style'=>'float:left;'), RCView::input(array('name'=>'theme_text_title', 'type'=>'text', 'class'=>'theme-property', 'size'=>4))) .
										RCView::div(array('style'=>'clear:both;float:left;width:110px;padding-top:10px;'), RCView::tt('folders_09')) .
										RCView::div(array('style'=>'float:left;padding-top:2px;'), RCView::input(array('name'=>'theme_bg_title', 'type'=>'text', 'class'=>'theme-property', 'size'=>4)))
									) .
									// Section headers
									RCView::td(array('style'=>'padding:3px 0 5px 8px;border-right:1px solid #ccc;'),
										RCView::div(array('style'=>'font-weight:bold;margin-bottom:5px;'), RCView::tt('survey_1027')) .
										RCView::div(array('style'=>'float:left;width:110px;padding-top:8px;'), RCView::tt('folders_08')) .
										RCView::div(array('style'=>'float:left;'), RCView::input(array('name'=>'theme_text_sectionheader', 'type'=>'text', 'class'=>'theme-property', 'size'=>4))) .
										RCView::div(array('style'=>'clear:both;float:left;width:110px;padding-top:10px;'), RCView::tt('folders_09')) .
										RCView::div(array('style'=>'float:left;padding-top:2px;'), RCView::input(array('name'=>'theme_bg_sectionheader', 'type'=>'text', 'class'=>'theme-property', 'size'=>4)))
									) .
									// Questions
									RCView::td(array('style'=>'padding:3px 0 5px 8px;'),
										RCView::div(array('style'=>'font-weight:bold;margin-bottom:5px;'), RCView::tt('survey_1026')) .
										RCView::div(array('style'=>'float:left;width:110px;padding-top:8px;'), RCView::tt('folders_08')) .
										RCView::div(array('style'=>'float:left;'), RCView::input(array('name'=>'theme_text_question', 'type'=>'text', 'class'=>'theme-property', 'size'=>4))) .
										RCView::div(array('style'=>'clear:both;float:left;width:110px;padding-top:10px;'), RCView::tt('folders_09')) .
										RCView::div(array('style'=>'float:left;padding-top:2px;'), RCView::input(array('name'=>'theme_bg_question', 'type'=>'text', 'class'=>'theme-property', 'size'=>4)))
									)
								)
							);

        print 	RCView::tr(array('id'=>'row_custom_theme'),
            RCView::td(array('colspan'=>'3', 'style'=>'padding:10px 0 0;'),
                RCView::div(array('style'=>'font-weight:bold;font-size:13px;color:#800000;margin-bottom:4px;'),
                    RCView::tt('survey_1031')
                ) .
                RCView::div(array('style'=>'font-weight:bold;font-size:13px;margin: 0 0 4px 22px;'),
                    RCView::span(array(), RCView::tt('survey_1016')).
                    RCView::input(array('name'=>'theme_name', 'type'=>'text', 'class'=>'theme-property mx-1')).
                    RCView::label(array('for'=>'theme_active'), 
                        RCView::input(array('id'=>'theme_active', 'name'=>'active', 'type'=>'checkbox', 'class'=>'theme-property mx-1')).
                        RCView::tt('econsent_02') // Active?
                    ).
                    RCView::input(array('name'=>'id', 'type'=>'hidden', 'class'=>'theme-property')).
                    RCView::input(array('name'=>'editable', 'type'=>'checkbox', 'class'=>'theme-property', 'style'=>'visibility:hidden;'))
                ) .
                $custom_theme_opts .
                RCView::div(array('style'=>'margin:10px 0 10px 25px;'),
                    // Save theme button
                    RCView::button(array('id'=>'saveThemeBtn', 'class'=>'btn btn-xs btn-success mr-2', 
                        'onclick'=>$this->getJavascriptModuleObjectName().'.saveTheme();'),
                        RCView::span(array('style'=>'vertical-align:middle;'), '<i class="fas fa-plus-circle mr-1"></i>'.RCView::tt('survey_1034'))
                    ).
                    RCView::span(array('id'=>'saveResultMsg', 'style'=>'vertical-align:middle;'), '')
                )
            )
        );
?>
        </div>
<style type="text/css">
    #<?=$tableId?> th { text-align: center;}
    .colour-block-container { text-align:center; text-transform: uppercase; }
    #theme-properties { display: none; }
</style>
<!-- Style/JavaScript needed for colour pickers -->
<link rel='stylesheet' href='<?php echo APP_PATH_CSS ?>spectrum.css'>
<?php loadJS('Libraries/spectrum.js'); ?>
<script type="text/javascript">
    /* External Module: Manage Default Survey Settings */
    (() => {
        let module = <?=$this->getJavascriptModuleObjectName()?>;
        module.tableid = '<?=$tableId?>';
        module.colourFields = JSON.parse('<?=json_encode(SurveyTheme::$ColourFields)?>');
        module.lengthOpt = [20, -1];
        module.lengthLbl = [20, "<?=RCView::tt_js('docs_44')?>"]; // "ALL"
        module.renderColourSetting = function(data, type, row) {
            return '<div class="colour-block-container"><div class="colour-block" style="background-color:'+data+';width:100%;">&nbsp;</div><span class="colour-block-hex;">'+data+'</span></div>';
        };
        module.initSpectrum = function(element, color) {
            // Initialize jQuery spectrum widget for themes
            if (color == null || color == '' || color == '#') color = '';
            $(element).val(color);
            $(element).spectrum({
                showInput: true, 
                preferredFormat: 'hex',
                color: color,
                localStorageKey: 'redcap',
                change: function(color) { $(element).val(color) }
            });
        }
        module.editTheme = function(id) {
            //console.log(id);
            module.ajax('get-theme', id).then(function(data) {
                //console.log(data);
                if (!data.editable) {
                    $('#theme-properties').hide();
                    return;
                }
                $('input[name="id"]').val(data.id);
                $('input[name="theme_name"]').val(data.theme_name);
                $('input[name="editable"]').prop('checked', data.editable);
                $('input[name="active"]').prop('checked', data.active);
                module.colourFields.forEach(colourField => {
                    $('input[name="'+colourField+'"]').val(data[colourField]);
                    module.initSpectrum('input[name="'+colourField+'"]', data[colourField]);
                });
                $('#saveThemeBtn').prop('disabled', false);
                $('#saveResultMsg').removeClass('text-success text-danger').html('');
                $('#theme-properties').show();
            });
        };
        module.saveTheme = function() {
            let themeObj = {};
            $('#saveThemeBtn').prop('disabled', true);
            $('#saveResultMsg').removeClass('text-success text-danger').html('');
            $('#theme-properties input.theme-property').each(function(i) {
                let v;
                let n = $(this).attr('name');
                if ($(this).attr('type')=='checkbox') {
                    v = $(this).prop('checked');
                } else {
                    v = $(this).val()
                }
                themeObj[n] = v;
            });
            console.log(themeObj);
            module.ajax('save-theme', themeObj).then(function(data) {
                console.log(data);
                if (!data.hasOwnProperty('result')) data.result = 0;
                if (!data.hasOwnProperty('message')) data.message = window.woops;
                module.performTableRefresh();
                if (data.result) {
                    $('#saveResultMsg').addClass('text-success').html('<i class="fas fa-check-circle mr-1"></i>'+data.message);
                    setTimeout(function(){ $('#theme-properties').slideUp();}, 2000);
                } else {
                    $('#saveResultMsg').addClass('text-danger').html('<i class="fas fa-exclaimation-triangle mr-1"></i>'+data.message);
                    $('#saveThemeBtn').prop('disabled', false);
                }
            });
        };

        module.init = function() {
            module.ajax('get-all-themes', null).then(function(data) {
                $('#'+module.tableid).DataTable( {
                    "pageLength": module.lengthOpt[0],
                    "lengthMenu": [module.lengthOpt, module.lengthLbl],
                    "lengthChange": true,
                    "stateSave": true,
                    "stateDuration": 0,
                    "dataSrc": "payload",
                    "data": data,
                    columns: [
                        { 
                            data: 'id',
                            render: function( data, type, row ) {
                                let editBtn = '';
                                if (row.editable) {
                                    editBtn = '<button type="button" class="edit-theme btn btn-xs btn-primaryrc" style="color:#fff" onclick="<?=$this->getJavascriptModuleObjectName()?>.editTheme('+data+')"><i class="fas fa-pencil"></i></button>';
                                }
                                return editBtn;
                            }
                        },
                        { data: 'theme_name' },
                        { 
                            data: 'active', 
                            render: function( data, type, row ) {
                                let icon = (!!data) ? '<i class="fas fa-circle-check text-success"></i>' : '<i class="fas fa-times text-danger"></i>';
                                return '<div class="text-center">'+icon+'</div>';
                            }
                        },
                        { data: 'theme_bg_page', render: module.renderColourSetting },
                        { data: 'theme_text_buttons', render: module.renderColourSetting },
                        { data: 'theme_text_title', render: module.renderColourSetting },
                        { data: 'theme_bg_title', render: module.renderColourSetting },
                        { data: 'theme_text_sectionheader', render: module.renderColourSetting },
                        { data: 'theme_bg_sectionheader', render: module.renderColourSetting },
                        { data: 'theme_text_question', render: module.renderColourSetting },
                        { data: 'theme_bg_question', render: module.renderColourSetting }
                    ]
                } );
            });
        };

        module.performTableRefresh = function() {
            module.ajax('get-all-themes', null).then(function(data) {
                var dt = $('#'+module.tableid).DataTable();
                dt.clear();
                dt.rows.add(data);
                dt.draw();
            });
        };

        $(document).ready(function(){
            module.init();
        });
    })();
</script>
<?php 
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) 
    {
        switch ($action) {
            case 'get-all-themes':
                $result = $this->getThemeTableData(); break;
            case 'get-theme':
                $result = $this->getTheme($payload); break;
            case 'save-theme':
                $result = $this->saveTheme($payload); break;
            default: 
                $result = []; break;
        }
        return $result;
    }

    protected function getThemeTableData()
    {
        $defaultTheme = SurveyTheme::constructThemeFromId(0, $this);
        $data = array($defaultTheme);

        $q = $this->query("select theme_id from redcap_surveys_themes where ui_id is null order by theme_id",[]);
        while($row = $q->fetch_assoc()){
            $data[] = SurveyTheme::constructThemeFromId($row['theme_id'], $this);
        }
        return $data;
    }

    protected function getTheme($id)
    {
        if (is_null($id) || (intval($id)==0 && $id!=0)) {
            $theme = SurveyTheme::constructNewTheme(); 
        } else {
            $theme = SurveyTheme::constructThemeFromId($id, $this); 
        }
        return $theme;
    }

    protected function saveTheme($data)
    {
        $result = array('result'=>1,'message'=>'');

        if (!is_array($data)) {
            $result['result'] = 0;
            $result['message'] = 'Unexpected data submited';
        } else {
            $theme = SurveyTheme::constructThemeFromArray($data);
            try {
                $theme->saveTheme($this);
                $result['message'] = RCView::tt('control_center_4879'); // Saved!
            } catch (\Throwable $t) {
                $result['result'] = 0;
                $result['message'] = $t->getMessage();
            }
        }
        return $result;
    }
}