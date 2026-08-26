[global]
type=global
user_agent=AF-PBX
endpoint_identifier_order=auth_username,username,ip,header,anonymous
max_initial_qualify_time=15
keep_alive_interval=90

[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060
external_media_address=185.253.8.33
external_signaling_address=185.253.8.33
local_net=185.253.8.0/24
local_net=172.16.0.0/12
local_net=127.0.0.1/32
tos=cs3
cos=3

; ---------- Beeline 3888 ----------
[beeline-3888-auth]
type=auth
auth_type=userpass
username=3888
password=${BEELINE_PASSWORD}
realm=vpbx-company-3075.CLOUDPBX.BEELINE.KZ

[beeline-3888-reg]
type=registration
transport=transport-udp
outbound_auth=beeline-3888-auth
server_uri=sip:vpbx-company-3075.CLOUDPBX.BEELINE.KZ
client_uri=sip:3888@vpbx-company-3075.CLOUDPBX.BEELINE.KZ
contact_user=3888
outbound_proxy=sip:46.227.186.231:6050\;lr
retry_interval=30
forbidden_retry_interval=60
expiration=120
auth_rejection_permanent=no
line=yes
endpoint=beeline-3888

[beeline-3888]
type=aor
contact=sip:vpbx-company-3075.CLOUDPBX.BEELINE.KZ
qualify_frequency=0
qualify_timeout=5.0
outbound_proxy=sip:46.227.186.231:6050\;lr

[beeline-3888]
type=endpoint
transport=transport-udp
context=from-beeline-3888
disallow=all
allow=alaw
allow=ulaw
outbound_auth=beeline-3888-auth
outbound_proxy=sip:46.227.186.231:6050\;lr
aors=beeline-3888
from_user=3888
from_domain=vpbx-company-3075.CLOUDPBX.BEELINE.KZ
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
ice_support=no
inband_progress=yes
dtmf_mode=rfc4733
language=ru
identify_by=auth_username,username,header

[beeline-3888-to]
type=identify
endpoint=beeline-3888
match_header=To: /3888/

; ---------- Beeline 8099 ----------
[beeline-8099-auth]
type=auth
auth_type=userpass
username=8099
password=${BEELINE_PASSWORD}
realm=vpbx-company-3075.CLOUDPBX.BEELINE.KZ

[beeline-8099-reg]
type=registration
transport=transport-udp
outbound_auth=beeline-8099-auth
server_uri=sip:vpbx-company-3075.CLOUDPBX.BEELINE.KZ
client_uri=sip:8099@vpbx-company-3075.CLOUDPBX.BEELINE.KZ
contact_user=8099
outbound_proxy=sip:46.227.186.231:6050\;lr
retry_interval=30
forbidden_retry_interval=60
expiration=120
auth_rejection_permanent=no
line=yes
endpoint=beeline-8099

[beeline-8099]
type=aor
contact=sip:vpbx-company-3075.CLOUDPBX.BEELINE.KZ
qualify_frequency=0
qualify_timeout=5.0
outbound_proxy=sip:46.227.186.231:6050\;lr

[beeline-8099]
type=endpoint
transport=transport-udp
context=from-beeline-8099
disallow=all
allow=alaw
allow=ulaw
outbound_auth=beeline-8099-auth
outbound_proxy=sip:46.227.186.231:6050\;lr
aors=beeline-8099
from_user=8099
from_domain=vpbx-company-3075.CLOUDPBX.BEELINE.KZ
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
ice_support=no
inband_progress=yes
dtmf_mode=rfc4733
language=ru
identify_by=auth_username,username,header

[beeline-8099-to]
type=identify
endpoint=beeline-8099
match_header=To: /8099/

; ---------- Bitrix incoming trunks ----------
[sip35-auth]
type=auth
auth_type=userpass
username=sip35
password=${B24_SIP35_PASSWORD}

[sip35]
type=aor
contact=sip:sip35@ip.b24-7297-1638417655.bitrixphone.com:5060
qualify_frequency=30
qualify_timeout=5.0

[sip35]
type=endpoint
transport=transport-udp
context=default
disallow=all
allow=alaw
allow=ulaw
outbound_auth=sip35-auth
aors=sip35
from_user=sip35
from_domain=ip.b24-7297-1638417655.bitrixphone.com
trust_id_outbound=yes
send_pai=yes
send_rpid=yes
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
ice_support=no
dtmf_mode=rfc4733
language=ru

[sip36-auth]
type=auth
auth_type=userpass
username=sip36
password=${B24_SIP36_PASSWORD}

[sip36]
type=aor
contact=sip:sip36@ip.b24-7297-1638417655.bitrixphone.com:5060
qualify_frequency=30
qualify_timeout=5.0

[sip36]
type=endpoint
transport=transport-udp
context=default
disallow=all
allow=alaw
allow=ulaw
outbound_auth=sip36-auth
aors=sip36
from_user=sip36
from_domain=ip.b24-7297-1638417655.bitrixphone.com
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
ice_support=no
dtmf_mode=rfc4733
language=ru

; ---------- Bitrix outbound (Voximplant -> Asterisk) ----------
[b24-3888-auth]
type=auth
auth_type=userpass
username=3888
password=${BEELINE_PASSWORD}

[b24-3888]
type=aor
max_contacts=5
remove_existing=yes
qualify_frequency=0

[b24-3888]
type=endpoint
transport=transport-udp
context=from-bitrix-3888
disallow=all
allow=alaw
allow=ulaw
auth=b24-3888-auth
aors=b24-3888
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
ice_support=no
dtmf_mode=rfc4733
language=ru
identify_by=auth_username,username,header

[b24-3888-from]
type=identify
endpoint=b24-3888
match_header=From: /sip:3888@/

[b24-8099-auth]
type=auth
auth_type=userpass
username=8099
password=${BEELINE_PASSWORD}

[b24-8099]
type=aor
max_contacts=5
remove_existing=yes
qualify_frequency=0

[b24-8099]
type=endpoint
transport=transport-udp
context=from-bitrix-8099
disallow=all
allow=alaw
allow=ulaw
auth=b24-8099-auth
aors=b24-8099
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
ice_support=no
dtmf_mode=rfc4733
language=ru
identify_by=auth_username,username,header

[b24-8099-from]
type=identify
endpoint=b24-8099
match_header=From: /sip:8099@/
