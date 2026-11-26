<?php /* #?ini charset="utf-8"?

# Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).

[RegionalSettings]
TranslationExtensions[]=tron

[RoleSettings]
# The MCP endpoint signs callers in with tron tokens, not sessions; each call checks the token user's own policies.
# It still answers 404 until tron.ini [HTTPSettings] Enabled=true lists the siteaccess.
PolicyOmitList[]=tron/mcp

# "Verify it's you" before making, rotating or revoking a token and before switching a program or tool (Setup > tron).
# Seeing the pages is never asked. tron.ini itself is changed in the kernel's INI editor, which the group "system"
# already asks for (settings/edit).
[ReauthSettings]
Groups[]=tron

[ReauthGroup_tron]
Enabled=true
Rules[]
Rules[]=tron/tokens CreateButton
Rules[]=tron/tokens RotateButton
Rules[]=tron/tokens RevokeButton
Rules[]=tron/programs SwitchButton
# Agent rules (Setup > tron > Agent rules): saving, uploading or removing AGENTS.md or the MASTER.md override, the admin
# toggle and the file marker. The questionnaire, the expai draft and one's own preference are never asked.
Rules[]=tron/rules SaveAgentsButton UploadAgentsButton RemoveAgentsButton
Rules[]=tron/rules SaveMasterButton UploadMasterButton RemoveMasterButton
Rules[]=tron/rules AdminToggleButton MarkerButton
# Skills (Setup > tron > Skills): approving (a skill, a pack, a draft), revoking, switching a pack and forking.
Rules[]=tron/skills ApproveButton ApprovePackButton RevokeButton
Rules[]=tron/skills PackSwitchButton ForkButton

*/ ?>
