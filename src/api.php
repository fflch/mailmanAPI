<?php

namespace splattner\mailmanapi;

use GuzzleHttp\Client;

class MailmanAPI {

    private $mailmanURL;
    private $password;
    private $client;

    /**
     * @param $mailmanurl
     *  Mailman Base URL
     * @param $password
     *  Administration Passwort for your Mailman List
     */
    public function __construct($mailmalurl, $password, $validade_ssl_certs = true) {

        $this->mailmanURL = $mailmalurl;
        $this->password = $password;

        $this->client = new Client(['base_uri' => $this->mailmanURL, 'cookies' => true, 'verify' => $validade_ssl_certs]);

        $response = $this->client->request('POST', '', [
            'form_params' => [
                'adminpw' => $this->password
            ]
        ]);

    }


    /**
     * Return Array of all Members in a Mailman List
     */
    public function getMemberlist() {

        $response = $this->client->request('GET', $this->mailmanURL . '/members');

        $dom = new \DOMDocument('1.0', 'UTF-8');

        // set error level
        $internalErrors = libxml_use_internal_errors(true);

        $dom->loadHTML($response->getBody());

        // Restore error level
        libxml_use_internal_errors($internalErrors);

        $tables = $dom->getElementsByTagName("table")[4];

        $trs = $tables->getElementsByTagName("tr");

        // Get all the urs for the letters
        $letterLinks = $trs[1];
        $links = $letterLinks->getElementsByTagName("a");

        $memberList = array();

        if (count($links) === 0) {
            return $this->getMembersFromTableRows($trs, $isSinglePage = true);
        }

        $urlsForLetters = array();

        foreach($links as $link) {
            $urlsForLetters[] =  $link->getAttribute('href');
        }

        foreach($urlsForLetters as $url) {
            $response = $this->client->request('GET', $url);

            $dom = new \DOMDocument('1.0', 'UTF-8');

            // set error level
            $internalErrors = libxml_use_internal_errors(true);

            $dom->loadHTML($response->getBody());

            // Restore error level
            libxml_use_internal_errors($internalErrors);

            $tables = $dom->getElementsByTagName("table")[4];
            $trs = $tables->getElementsByTagName("tr");

            $memberList = array_merge(
                $memberList,
                $this->getMembersFromTableRows($trs)
            );
        }

        return $memberList;
    }

    /**
     * Get the e-mail addresses from a list of table rows (<tr>).
     *
     * @param  DOMNodeList  $trs
     * @param  bool        $isSinglePage
     *
     * @return array
     */
    protected function getMembersFromTableRows($trs, $isSinglePage = false)
    {
        $firstRowIndex = $isSinglePage ? 2 : 3;

        $memberList = [];

        for ($i = $firstRowIndex; $i < $trs->length; $i++) {
            $tds = $trs[$i]->getElementsByTagName("td");
            $memberList[] = $tds[1]->nodeValue;
        }

        return $memberList;
    }

    /**
     * Add new Members to a Mailman List
     * @param $members
     *  Array of Members that should be added
     * @return
     *  Array of Members that were successfully added
     */
    public function addMembers($members) {

        $token = $this->getCSRFToken("members/add");

        $response = $this->client->request('POST', $this->mailmanURL . '/members/add', [
            'form_params' => [
                'csrf_token' => $token,
                'subscribe_or_invite' => '0',
                'send_welcome_msg_to_this_batch' => '0',
                'send_notifications_to_list_owner' => '0',
                'subscribees' => join(chr(10), $members),
                'setmemberopts_btn' => 'Änderungen speichern'
            ]
        ]);


        return $this->parseResultList($response->getBody());
    }

    /**
     * Remove Members to a Mailman List
     * @param $members
     *  Array of Members that should be added
     * @return
     *  Array of Members that were successfully removed
     */
    public function removeMembers($members) {

        $token = $this->getCSRFToken("members/remove");

        $response = $this->client->request('POST', $this->mailmanURL . '/members/remove', [
            'form_params' => [
                'csrf_token' => $token,
                'send_unsub_ack_to_this_batch' => '0',
                'send_unsub_notifications_to_list_owner' => '0',
                'unsubscribees' => join(chr(10), $members),
                'setmemberopts_btn' => 'Änderungen speichern'
            ]
        ]);

        return $this->parseResultList($response->getBody());
    }

    /**
     * Change Address for a member
     * @param $memberFrom
     *  The Adress from the member you wanna change
     * @param $memberTo
     *  The Adress it should be changed to

     */
    public function changeMember($memberFrom, $memberTo) {

        $token = $this->getCSRFToken("members/change");
        $response = $this->client->request('POST', $this->mailmanURL . '/members/change', [
            'form_params' => [
                'csrf_token' => $token,
                'change_from' => $memberFrom,
                'change_to' => $memberTo,
                'setmemberopts_btn' => 'Änderungen speichern'
            ]
        ]);

        $dom = new \DOMDocument('1.0', 'UTF-8');

        // set error level
        $internalErrors = libxml_use_internal_errors(true);

        $dom->loadHTML($response->getBody());

        // Restore error level
        libxml_use_internal_errors($internalErrors);

        $h3 = $dom->getElementsByTagName("h3")[0];

        return (strpos($h3->nodeValue, $memberFrom) == True && strpos($h3->nodeValue, $memberTo) == True);

    }

    /**
     * Parse the HTML Body of an Add or Remove Action to get List of successfull add/remove entries
     * @param $body
     *  the HTML Body of the Result Page
     * @return
     * Array of Entrys that were successfull
     */
    private function parseResultList($body) {

        $dom = new \DOMDocument('1.0', 'UTF-8');

        // set error level
        $internalErrors = libxml_use_internal_errors(true);

        $dom->loadHTML($body);

        // Restore error level
        libxml_use_internal_errors($internalErrors);

        $result = array();

        // Are there entrys with success?
        $haveSuccessfullEntry = $dom->getElementsByTagName("h5")[0] != null;

        if ($haveSuccessfullEntry) {
            $uls = $dom->getElementsByTagName("ul")[0];
            $lis = $uls->getElementsByTagName("li");

            foreach($lis as $li) {
                // Warning after --
                if (strpos($li->nodeValue, '--') == False) {
                    $result[] = $li->nodeValue;
                }
            }
        }

        return $result;
    }

    /*
     * Get CSRF Token for a Page
     * @param $page
     *  the Page you want the token for
     */
    private function getCSRFToken($page) {

        $response = $this->client->request('GET', $this->mailmanURL . '/' . $page);

        $dom = new \DOMDocument('1.0', 'UTF-8');

        // set error level
        $internalErrors = libxml_use_internal_errors(true);

        $dom->loadHTML($response->getBody());

        // Restore error level
        libxml_use_internal_errors($internalErrors);

        $form = $dom->getElementsByTagName("form")[0];
        return $form->getElementsByTagName("input")[0]->getAttribute("value");
    }

    /**
     * Set privacy/sender configuration
     * @param $nonmembers
     *  Array of nonmembers that should be added
     * @return
     *  null
     */
    public function configPrivacySender($nonmembers) {

        $token = $this->getCSRFToken("privacy/sender");
        $response = $this->client->request('POST', $this->mailmanURL . '/privacy/sender', [
            'form_params' => [
                'csrf_token' => $token,
              /* Por padrão, as postagens de novos membros da lista devem ser moderadas? 
               *  Resposta: Sim*/
                'default_member_moderation' => '1',

                'member_moderation_action' => '1',

                'member_moderation_notice' => '',
                'accept_these_nonmembers' => join(chr(10), $nonmembers),
                'hold_these_nonmembers' => '',
                'reject_these_nonmembers' => '',
                'discard_these_nonmembers' => '',
                'nonmember_rejection_notice' => '',

                'generic_nonmember_action' => '2',
                'forward_auto_discards' => '0',
                'submit' => 'Send'
            ]
        ]);

        return $response;
    }

     /*
     * Set general configuration
     */
    public function configGeneral($real_name,$owner,$subject_prefix, $host_name = 'listas.usp.br') {
        $token = $this->getCSRFToken("general");

        $response = $this->client->request('POST', $this->mailmanURL . '/general', [
            'form_params' => [
                'csrf_token' => $token,
                /*O nome público da lista (faça somente modificações capitalizadas).
                 * Resposta: eventosdf_fflch
                 */
                'real_name' => $real_name,
                /*O endereço de email do administrador da lista. São permitidos múltiplos endereços:
                 */
                'owner' => $owner,
                /*O endereço de email do moderador da lista. No caso de múltiplos endereços de moderador:
                 */
                'moderator' => $owner,
                /*Uma frase resumo identificando esta lista.
                 *Resposta:
                 */
                'description' => '',
                /*Uma descrição introdutória - em poucos parágrafos - sobre a lista. Ela será incluída, como html, 
                 *no topo da página listinfo. O pressionamento de enter finaliza um parágrafo 
                 *- veja os detalhes para mais informações.
                 *Resposta:
                 */
                        'info' => '',
                /*Prefixo colocado na linha de assunto das postagens nas listas.
                 *Resposta:[EventosDF]
                 */
                        'subject_prefix' => "[{$subject_prefix}]",
                /*Ocultar o remetente da mensagem, substituindo-o pelo endereço do nome da lista (Remove o campo From, Sender e Reply-To)
                 *Resposta:Não
                 */
                'anonymous_list' => '0',
                /*Qualquer cabeçalho Reply-To: encontrado na mensagem original deverá ser retirados? 
                 *Caso isto aconteça, isto será feito mesmo que o cabeçalho Reply-To: seja adicionado ou não pelo Mailman.
                 *Resposta:Não
                 */
                'first_strip_reply_to' => '0',
                /*Onde as respostas para as mensagens desta lista deverão ser direcionadas? 
                 *Remetente é extremamente recomendado para a maioria das listas de discussão.
                 *Resposta:Remetente
                 */
                'reply_goes_to_list' => '0',
                /*Cabeçalho Reply-To: explicito.
                 *Resposta:
                 */
                'reply_to_address' => '',
                /*Enviar lembretes de senhas para o endereço, eg, "-owner" ao invés de diretamente para o usuário.
                 *Resposta:Sim
                 */
                'umbrella_list' => '1',
                /*Sufixo que será usado quando esta lista for cascateada para outras listas, 
                 *de acordo com a configuração anterior "umbrella_list".
                 *Resposta:-owner
                 */
                'umbrella_member_suffix' => '-owner',
                /*Enviar lembretes mensais de senha?
                 *Resposta:Não
                 */
                'send_reminders' => '0',
                /*Texto específico da lista adicionado a mensagem de boas vindas do novo inscrito
                 *Resposta:
                 */
                'welcome_msg' => '',
                /*Enviar mensagem de boas vindas para novos membros inscritos?
                 *Resposta:Não
                 */
                'send_welcome_msg' => '0',
                /*Texto que será enviado a pessoas deixando a lista. Caso esteja vazio, 
                 *nenhum texto especial será adicionado a mensagem de remoção.
                 *Resposta:Sua inscrição nesta lista foi cancelada.
                 */
                'goodbye_msg' => 'Sua inscrição nesta lista foi cancelada.',
                /*Enviar mensagens de boas vindas para membros quando eles são desinscritos.
                 *Resposta:Não
                 */
                'send_goodbye_msg' => '0',
                /*Os moderadores de lista devem obter uma notificação imediata de novas requisição, 
                 *assim como também as notícias diárias coletadas?
                 *Resposta:Não
                 */
                'admin_immed_notify' => '0',
                /*O administrador deverá receber notificações de inscrições e desinscrições?
                 *Resposta:Não
                 */
                'admin_notify_mchanges' => '0',
                /*Enviar um email para o remetente quando sua postagem está aguardando aprovação?
                 *Resposta:Não
                 */
                'respond_to_post_requests' => '0',
                /*Moderação de emergência para o tráfego de todas as listas:
                 *Resposta:Não
                 */
                'emergency' => '0',
                /*Opções padrões para novos membros entrando nesta lista.
                 *Resposta:Esconder o endereço do membro / Não enviar uma cópia da própria postagem do membro / Filtrar mensagens
                 *duplicadas de membros da lista (se possível)
                 */
                //'new_member_options' => array('hide', 'notmetoo', 'nodupes'),
                /*(Filtro Administrivia) Verifica postagens e intercepta aquelas que se parecem com requisições administrativas.
                 *Resposta:Não
                 */
                'administrivia' => '0',
                /*Tamanho máximo em kilobytes (KB) do corpo da mensagem. Use 0 para não ter limite.
                 *Resposta:0
                 */
                'max_message_size' => '0',
                /*Maximum number of members to show on one page of the Membership List.
                 *Resposta:20000
                 */
                'admin_member_chunksize' => '100000',
                /*Nome de máquina que esta listas prefere para emails.
                 *Resposta:listas.usp.br
                 */
                'host_name' => $host_name,
                /*As mensagens desta lista de discussão devem incluir os cabeçalhos da RFC 2369 
                 *(i.e. List-*? Sim é altamente recomendável.
                 *Resposta:Não
                 */
                'include_rfc2369_headers' => '0',
                /*As postagens devem incluir o cabeçalho List-Post:?
                 *Resposta:Não
                 */
                'include_list_post_header' => '0',
                /*Should the Sender header be rewritten for this mailing list to avoid stray bounces? Yes is recommended.
                 *Resposta:Sim
                 */
                'include_sender_header' => '1',
                /*Descartar mensagens mantidas que ultrapassam esta quantidade de dias. Use 0 para não descartar automaticamente.
                 *Resposta:1
                 */
                'max_days_to_hold' => '1',
                'submit' => 'Send'
        ]
    ]);
        return $response;
    }


    /**
     * Set privacy/subscribing configuration
     */
    public function configPrivacySubscribing() {
        $token = $this->getCSRFToken("privacy/subscribing");

        $response = $this->client->request('POST', $this->mailmanURL . '/privacy/subscribing', [
            'form_params' => [
                'csrf_token' => $token,
                /*Avisar esta lista quando pessoas perguntarem que listas estão nesta máquina?
                 *Resposta:Não
                 */
                'advertised' => '0',
            
                /*Que passos são requeridos para a inscrição?
                 *Resposta:Confirmar e aprovar
                 */
                'subscribe_policy' => '2',
            
                /**
                 * List of addresses (or regexps) whose subscriptions do not require approval.
                 */
                'subscribe_auto_approval' => '',

                /*É requerida a aprovação do moderador para requisições de remoção? (Não é recomendado).
                 *Resposta:Não
                 */
                'unsubscribe_policy' => '0',
            
                /*Lista de endereços que estão banidos de serem membros desta lista de discussão.
                 *Resposta:
                 */
                'ban_list' => '',
            
                /*Quem poderá ver a lista de inscrição?
                 *Resposta:Somente administradores da lista
                 */
                'private_roster' => '2',
            
                /*Mostra endereços de membros assim eles não serão reconhecidos diretamente como endereços de email?
                 *Resposta:Sim
                 */
                'obscure_addresses' => '1',
                'submit' => 'Send'
            ]
        ]);
        return $response;
    }

    /**
     * Set privacy/recipient configuration
     */
    public function configPrivacyRecipient() {
        $token = $this->getCSRFToken("privacy/recipient");
        $response = $this->client->request('POST', $this->mailmanURL . '/privacy/recipient', [
            'form_params' => [
                'csrf_token' => $token,
                /* As postagens devem ter o nome da lista no campo destino (to, cc) 
                 * da lista (ou estar junto de nomes de aliases, especificados abaixo)?
                 * Resposta: Não
                 */
                'require_explicit_destination' => '0',
        
                /*Nomes aliases (expressões) que qualificam os nomes de destinos to e cc para esta lista.
                *Resposta:
                */
                'acceptable_aliases' => '',

                /*Pondo um limite aceitável no número de recipientes para postagem.
                *Resposta: 0
                */
                'max_num_recipients' => '0',
                'submit' => 'Send'
                ]
            ]);
        return $response;
    }

    /**
     * Set digest configuration
     */
    public function configDigest() {
        $token = $this->getCSRFToken("digest");
        $response = $this->client->request('POST', $this->mailmanURL . '/digest', [
            'form_params' => [
                'csrf_token' => $token,
                /*Os membros da lista podem receber o tráfego da lista dividido em digests?
                * Resposta:Sim
                */
                'digestable' => '1',

                /*Que modo de entrega é o padrão para novos usuários?
                * Resposta:Regular
                */
                'digest_is_default' => '0',

                /*Quando resolvendo digests, que formato é o padrão?
                * Resposta:Puro
                */
                'mime_is_default_digest' => '0',

                /*Qual é o tamanho em OK que o digest deverá ter antes de ser enviado?
                * Resposta:30
                */
                'digest_size_threshhold' => '30',

                /*O digest deverá ser despachado diariamente quando o tamanho dele não atingir o limite mínimo?
                * Resposta:Sim
                */
                'digest_send_periodic' => '1',

                        /*Cabeçalho adicionado a cada digest
                * Resposta:
                        */
                'digest_header' => '',

                /*Legenda adicionado a cada digest
                * Resposta:
                */
                'digest_footer' => '',

                /*Com que freqüência o volume do novo digest será iniciado?
                * Resposta:Anual
                */
                'digest_volume_frequency' => '0',
                
                /*O Mailman deve iniciar um novo volume digest.
                * Resposta:Não
                */
                '_new_volume' => '0',

                /*O Mailman deve enviar o próximo digest agora, caso não esteja vazio?
                * Resposta:Não
                */
                '_send_digest_now' => '0',
                'submit' => 'Send'
            ]
        ]);
        return $response;
    }

    /**
     * Set nondigest configuration
     */
    public function configNonDigest($msg_footer='',$msg_header='') {
        $token = $this->getCSRFToken("nondigest");
        $response = $this->client->request('POST', $this->mailmanURL . '/nondigest', [
            'form_params' => [
                'csrf_token' => $token,
                /*Os inscritos na lista podem receber um email imediatamente, ao invés de digests em lote?
                *Resposta:Sim
                */
                'nondigestable' => '1',

                /*Cabeçalho adicionado ao email enviado para membros regulares
                *Resposta:
                */
                'msg_header' => $msg_header,

                /*Rodapé adicionado ao email enviado para os membros regulares da lista
                *Resposta:
                */
                'msg_footer' => $msg_footer,

                /*Fazer link de anexos de mensagens de entregas regulares?
                *Resposta:Não
                */
                'scrub_nondigest' => '0',

                /*Other mailing lists on this site whose members are excluded from the regular (non-digest)
                *delivery if those list addresses appear in a To: or Cc: header.
                *Resposta:
                */
                'regular_exclude_lists' => '',

                /**
                 * Ignore regular_exclude_lists of which the poster is not a member.
                 */
                'regular_exclude_ignore' => 1,

                /*Other mailing lists on this site whose members are included in the regular (non-digest)
                *delivery if those list addresses don't appear in a To: or Cc: header.
                *Resposta:
                */
                'regular_include_lists' => '',
                'submit' => 'Send'
            ]
        ]);
        return $response;
    }

    /**
     * Set bounce configuration
     */
    public function configBounce() {
        $token = $this->getCSRFToken("bounce");

        $response = $this->client->request('POST', $this->mailmanURL . '/bounce', [
            'form_params' => [
                'csrf_token' => $token,
                /*O Mailman deverá fazer processamento automático de retornos?
                 *Resposta:Sim
                 */
                'bounce_processing' => '1',

                /*O número máximo de retornos antes de desativar a inscrição do membro. Este valor pode ser 
                 *um número de ponto flutuante.
                 *Resposta:5.0
                 */
                'bounce_score_threshold' => '5.0',


                /*O número de dias após descartar a informação de retorno d membro. Se nenhum bounce novo 
                 *for recebido interinamente. Este valor deverá ser um número.
                 *Resposta:7
                 */
                'bounce_info_stale_after' => '7',

                /*Quantos alertas Seu cadastro está desativado o membro da lista deverá receber antes do endereço ser removido
                 *da lista de discussão. Ajuste o valor para 0 para remover o endereço imediatamente da lista uma vez que sua pontuação
                 *de bounce exceder o valor definido. Este valor deverá ser um número.
                 *Resposta:0
                 */
                'bounce_you_are_disabled_warnings' => '0',

                /*O número de dias antes de enviar os alertas Seu cadastro está desativado. Este valor deverá ser um número.
                 *Resposta:0
                 */
                'bounce_you_are_disabled_warnings_interval' => '0',

                /*O Mailman deverá te enviar, o dono da lista, quaisquer mensagens de bounce que falharam ao ser detectadas
                 *pelo processador de bounces? Sim é recomendado.
                 *Resposta:Não
                 */
                'bounce_unrecognized_goes_to_list_owner' => '0',

                /**
                 * Should Mailman notify you, the list owner, when bounces cause a member's bounce score to be incremented?
                 */
                'bounce_notify_owner_on_bounce_increment' => '0',

                /*O Mailman deverá te notificar, o dono da lista, quando os bounces fazem a inscrição da lista ser desativada?
                 *Resposta:Não
                 */
                'bounce_notify_owner_on_disable' => '0',

                /*O Mailman deverá te notificar, o dono da lista, quando os bounces fizerem um membro ser descadastrado
                 *Resposta:Não
                 */
                'bounce_notify_owner_on_removal' => '0',
                'submit' => 'Send'
            ]
        ]);

        return $response;
    }

}


?>
