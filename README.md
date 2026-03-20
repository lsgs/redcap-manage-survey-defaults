# Manage Default Survey Settings

Luke Stevens, Murdoch Children's Research Institute https://www.mcri.edu.au

[https://github.com/lsgs/redcap-manage-survey-defaults/](https://github.com/lsgs/redcap-manage-survey-defaults/)

## Description

Add and edit new global survey themes and customise certain default options when enabling a form as a survey.

It is expected that this module be **enabled on all projects** to obtain the widest benefit from the abilty to specify alternative options as the default for new surveys, e.g. an institute-preferred font and branded theme. 

## Configuration

### Default Survey Settings

You can select alternative options to be used as the default choice for the following survey settings:
* Width of survey on page
* Use enhanced radio buttons and checkboxes?
* Size of survey text
* Font of survey text
* Survey theme
* Question numbering
* Pagination

### Manage Global Survey Themes

From the Control Center configuration dialog you can access a page wherein you may add and edit survey themes that will be available alongside the built-in survey themes in projects where the module is enabled.

You may make the built-in survey themes editable (and thereby set their visbility in projects) via the system settings dialog with one exception: the "Default" them may be disabled/hidden, but may not be edited because the colour settings are hard-coded in REDCap, not saved in the `redcap_survey_themes` table.

You may prevent the "Default" theme from being available in projects by selecting it as one of the "hidden" themes in the survey settings dialog.