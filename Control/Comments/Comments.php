<?php

/**
 * CommandCollection
 *
 * @author Team phpManufaktur <team@phpmanufaktur.de>
 * @link https://kit2.phpmanufaktur.de
 * @copyright 2013 Ralf Hertsch <ralf.hertsch@phpmanufaktur.de>
 * @license MIT License (MIT) http://www.opensource.org/licenses/MIT
 */

namespace phpManufaktur\CommandCollection\Control\Comments;

use phpManufaktur\Basic\Control\kitCommand\Basic;
use Silex\Application;
use phpManufaktur\CommandCollection\Data\Comments\Comments as CommentsData;
use phpManufaktur\Contact\Control\Contact;
use phpManufaktur\CommandCollection\Data\Comments\CommentsIdentifier;

class Comments extends Basic
{
    protected $CommentsData = null;
    protected $CommentsIdentifier = null;
    protected $ContactControl = null;
    protected $Configuration = null;

    protected static $parameter = null;
    protected static $configuration = null;
    protected static $contact = null;
    protected static $contact_id = -1;
    protected static $idenfifier = null;
    protected static $identifier_id = -1;
    protected static $submit = null;
    protected static $comment = null;
    protected static $comment_id = -1;

    protected static $publish_methods = array('EMAIL', 'ADMIN', 'IMMEDIATE');

    /**
     * (non-PHPdoc)
     *
     * @see \phpManufaktur\Basic\Control\kitCommand\Basic::initParameters()
     */
    protected function initParameters(Application $app, $parameter_id=-1)
    {
        parent::initParameters($app, $parameter_id);

        $this->CommentsData = new CommentsData($app);
        $this->CommentsIdentifier = new CommentsIdentifier($app);
        $this->ContactControl = new Contact($app);

        $this->Configuration = new Configuration($app);
        self::$configuration = $this->Configuration->getConfiguration();

        // get the parameters
        $params = $this->getCommandParameters();

        // check the publishing mode for new comments
        $publish = array();
        if (isset($params['publish'])) {
            if (strpos($params['publish'], ',')) {
                $pub = explode($params['publish']);
                foreach ($pub as $key) {
                    if (in_array(strtoupper(trim($key)), self::$publish_methods))
                        $publish[] = strtoupper(trim($key));
                }
                if (in_array('IMMEDIATE') && in_array(array('EMAIL', 'ADMIN'), $publish))
                    unset($publish[array_search('IMMEDIATE', $publish)]);
                if (empty($publish)) {
                    $publish[] = 'ADMIN';
                }
            }
            elseif (in_array(strtoupper(trim($params['publish'])))) {
                $publish[] = strtoupper(trim($params['publish']));
            }
            else {
                $publish[] = 'ADMIN';
            }
        }
        elseif (!self::$configuration['comments']['confirmation']['double_opt_in'] &&
                !self::$configuration['comments']['confirmation']['administrator']) {
            $publish[] = 'IMMEDIATE';
        }
        else {
            if (self::$configuration['comments']['confirmation']['double_opt_in']) {
                $publish[] = 'EMAIL';
            }
            if (self::$configuration['comments']['confirmation']['administrator']) {
                $publish[] = 'ADMIN';
            }
        }

        // check the parameters and set defaults
        self::$parameter = array(
            'captcha' => (isset($params['captcha']) && (($params['captcha'] == '0') || (strtolower(trim($params['captcha'])) == 'false'))) ? false : true,
            'type' => (isset($params['type']) && !empty($params['type'])) ? strtoupper($params['type']) : 'PAGE',
            'id' => (isset($params['id']) && is_numeric($params['id'])) ? intval($params['id']) : $this->getCMSpageID(),
            'publish' => $publish,
        );

        if (false === (self::$idenfifier = $this->CommentsIdentifier->selectByTypeID(self::$parameter['type'], self::$parameter['id']))) {
            // create a new identifier
            if (in_array('IMMEDIATE', self::$parameter['publish'])) {
                $publish_type = 'IMMEDIATE';
            }
            elseif (in_array(array('EMAIL', 'ADMIN'), self::$parameter['publish'])) {
                $publish_type = 'CONFIRM_EMAIL_ADMIN';
            }
            elseif (in_array('EMAIL', self::$parameter['publish'])) {
                $publish_type = 'CONFIRM_EMAIL';
            }
            else {
                $publish_type = 'CONFIRM_ADMIN';
            }
            $data = array(
                'identifier_type_name' => self::$parameter['type'],
                'identifier_type_id' => self::$parameter['id'],
                'identifier_mode' => 'EMAIL', // actual the only supported mode
                'identifier_publish' => $publish_type,
                'identifier_contact_tag' => '', // actual not supported
                'identifier_comments_type' => 'HTML', // actual the only supported mode
            );
            // insert the new identifier
            $this->CommentsIdentifier->insert($data, self::$idenfifier);
        }
        self::$identifier_id = self::$idenfifier['identifier_id'];

        // check if the contact tag type 'COMMENTS' exists
        if (!$this->ContactControl->existsTagName('COMMENTS')) {
            // create the tag type 'COMMENTS'
            $this->ContactControl->createTagName('COMMENTS',
                "This Tag type is created by the kitCommand 'Comments' and will be set for persons who leave a comment.");
            $this->app['monolog']->addInfo('Created the Contact Tag Type COMMENTS', array(__METHOD__, __LINE__));
        }

    }

    /**
     * Return the complete form for the submission
     *
     * @param array $data
     */
    protected function getCommentForm($data=array())
    {
        return $this->app['form.factory']->createBuilder('form')
        ->add('comment_id', 'hidden', array(
            'data' => isset($data['comment_id']) ? $data['comment_id'] : -1
        ))
        ->add('identifier_id', 'hidden', array(
            'data' => isset($data['identifier_id']) ? $data['identifier_id'] : -1
        ))
        ->add('comment_parent', 'hidden', array(
            'data' => isset($data['comment_parent']) ? $data['comment_parent'] : 0,
        ))
        ->add('comment_headline', 'text', array(
            'data' => isset($data['comment_headline']) ? $data['comment_headline'] : '',
            'label' => 'Headline',
            'required' => true
        ))
        ->add('comment_content', 'textarea', array(
            'data' => isset($data['comment_content']) ? $data['comment_content'] : '',
            'label' => 'Comment',
            'required' => true
        ))
        ->add('contact_id', 'hidden', array(
            'data' => isset($data['contact_id']) ? $data['contact_id'] : -1
        ))
        ->add('contact_nick_name', 'text', array(
            'data' => isset($data['contact_nick_name']) ? $data['contact_nick_name'] : '',
            'label' => 'Nickname',
            'required' => true
        ))
        ->add('contact_email', 'email', array(
            'data' => isset($data['contact_email']) ? $data['contact_email'] : '',
            'label' => 'E-Mail',
            'required' => true
        ))
        ->add('contact_url', 'url', array(
            'data' => isset($data['contact_homepage']) ? $data['contact_homepage'] : '',
            'label' => 'Homepage',
            'required' => false
        ))
        ->add('comment_update_info', 'choice', array(
            'choices' => array(1 => 'send email at new comment'),
            'multiple' => true,
            'expanded' => true,
            'required' => false
        ))
        ->getForm();
    }

    /**
     * Initialize the iFrame for ExcelRead
     * Return the Welcome page if no file is specified. otherwise show the Excel file
     *
     * @param Application $app
     */
    public function controllerInitFrame(Application $app)
    {
        // initialize only the Basic class, dont need additional initialisations
        parent::initParameters($app);

        // execute the Comments within the iFrame
        return $this->createIFrame('/collection/comments/view');
    }

    /**
     * Return the rendered comments thread and the submit form
     *
     * @param FormFactory $form
     * @return string
     */
    protected function promptForm($form)
    {
        return $this->app['twig']->render($this->app['utils']->templateFile(
            '@phpManufaktur/CommandCollection/Template/Comments',
            "comments.twig",
            $this->getPreferredTemplateStyle()),
            array(
                'parameter' => self::$parameter,
                'basic' => $this->getBasicSettings(),
                'form' => $form->createView()
            ));
    }

    /**
     * If the double-opt-in feature for new contacts is enabled the submitter
     * must activate the contact before he can submit a comment
     *
     * @param array $submit
     * @return string
     */
    protected function contactConfirmContact($form) {
        // create a comment record
        $this->createCommentRecord();

        $body = $this->app['twig']->render($this->app['utils']->getTemplateFile(
            '@phpManufaktur/CommandCollection/Template/Comments',
            'mail/contact/confirm.contact.twig',
            $this->getPreferredTemplateStyle()),
            array(
                'comment' => self::$comment,
                'activation_link' => FRAMEWORK_URL.'/collection/comments/contact/confirm/'.self::$comment['comment_guid']
            ));

        // send a email to the contact
        $message = \Swift_Message::newInstance()
            ->setSubject(self::$comment['comment_headline'])
            ->setFrom(array(self::$configuration['administrator']['email']))
            ->setTo(array(self::$comment['contact_email']))
            ->setBody($body)
            ->setContentType('text/html');
        // send the message
        $this->app['mailer']->send($message);

        $this->setMessage('Thank you for your comment. We have send you an activation link to confirm your email address. The email address will never published.');

        return $this->ControllerView($this->app);
    }

    /**
     * Send the contact a information that the email is activated but the
     * comment must be confirmed by the administrator
     *
     */
    protected function contactPendingConfirmation()
    {
        if (self::$configuration['contact']['information']['pending_confirmation']) {
            $body = $this->app['twig']->render($this->app['utils']->getTemplateFile(
                '@phpManufaktur/CommandCollection/Template/Comments',
                'mail/contact/pending.confirmation.twig',
                $this->getPreferredTemplateStyle()),
                array(
                    'comment' => self::$comment,
                ));

            // send a email to the contact
            $message = \Swift_Message::newInstance()
            ->setSubject(self::$comment['comment_headline'])
            ->setFrom(array(self::$configuration['administrator']['email']))
            ->setTo(array(self::$comment['contact_email']))
            ->setBody($body)
            ->setContentType('text/html');
            // send the message
            $this->app['mailer']->send($message);
        }
    }

    /**
     * Send the contact the information that the comment is just published
     *
     */
    protected function contactPublishedComment()
    {
        if (self::$configuration['contact']['information']['published_comment']) {
            $body = $this->app['twig']->render($this->app['utils']->getTemplateFile(
                '@phpManufaktur/CommandCollection/Template/Comments',
                'mail/contact/published.comment.twig',
                $this->getPreferredTemplateStyle()),
                array(
                    'comment' => self::$comment,
                ));

            // send a email to the contact
            $message = \Swift_Message::newInstance()
            ->setSubject(self::$comment['comment_headline'])
            ->setFrom(array(self::$configuration['administrator']['email']))
            ->setTo(array(self::$comment['contact_email']))
            ->setBody($body)
            ->setContentType('text/html');
            // send the message
            $this->app['mailer']->send($message);
        }
    }

    /**
     * Send the contact the information that the comment was REJECTED
     *
     */
    protected function contactRejectComment()
    {
        if (self::$configuration['contact']['information']['rejected_comment']) {
            $body = $this->app['twig']->render($this->app['utils']->getTemplateFile(
                '@phpManufaktur/CommandCollection/Template/Comments',
                'mail/contact/rejected.comment.twig',
                $this->getPreferredTemplateStyle()),
                array(
                    'comment' => self::$comment,
                ));

            // send a email to the contact
            $message = \Swift_Message::newInstance()
            ->setSubject(self::$comment['comment_headline'])
            ->setFrom(array(self::$configuration['administrator']['email']))
            ->setTo(array(self::$comment['contact_email']))
            ->setBody($body)
            ->setContentType('text/html');
            // send the message
            $this->app['mailer']->send($message);
        }
    }

    /**
     * Send the contact the information that the comment was REJECTED and
     * his account is LOCKED
     *
     */
    protected function contactLockContact()
    {
        if (self::$configuration['contact']['information']['rejected_comment']) {
            $body = $this->app['twig']->render($this->app['utils']->getTemplateFile(
                '@phpManufaktur/CommandCollection/Template/Comments',
                'mail/contact/locked.contact.twig',
                $this->getPreferredTemplateStyle()),
                array(
                    'comment' => self::$comment,
                ));

            // send a email to the contact
            $message = \Swift_Message::newInstance()
            ->setSubject(self::$comment['comment_headline'])
            ->setFrom(array(self::$configuration['administrator']['email']))
            ->setTo(array(self::$comment['contact_email']))
            ->setBody($body)
            ->setContentType('text/html');
            // send the message
            $this->app['mailer']->send($message);
        }
    }

    /**
     * Create a new comment record
     *
     */
    protected function createCommentRecord() {
        $comment = array(
            'identifier_id' => self::$identifier_id,
            'comment_parent' => self::$submit['comment_parent'],
            'comment_url' => $this->getCMSpageURL(),
            'comment_headline' => self::$submit['comment_headline'],
            'comment_content' => self::$submit['comment_content'],
            'comment_status' => 'PENDING',
            'comment_guid' => $this->app['utils']->createGUID(),
            'comment_guid_2' => $this->app['utils']->createGUID(),
            'comment_confirmation' => '0000-00-00 00:00:00',
            'comment_update_info' => isset(self::$submit['comment_update_info'][0]) ? 1 : 0,
            'contact_id' => self::$contact_id,
            'contact_nick_name' => self::$submit['contact_nick_name'],
            'contact_email' => self::$submit['contact_email'],
            'contact_url' => !is_null(self::$submit['contact_url']) ? self::$submit['contact_url'] : ''
        );
        $this->CommentsData->insert($comment, self::$comment_id);
        self::$comment = $this->CommentsData->select(self::$comment_id);
    }

    /**
     * Default Controller to view the comments for the given parameters and to
     * show a dialog to submit a new comment.
     *
     * @param Application $app
     * @return string
     */
    public function controllerView(Application $app)
    {
        // init parent and client
        $this->initParameters($app);

        $GET = $this->getCMSgetParameters();
        if (isset($GET['message'])) {
            // message submitted as CMS parameter
            $this->setMessage(base64_decode($GET['message']));
        }

        $form = $this->getCommentForm();

        $result = $this->CommentsData->getThread();

        return $this->promptForm($form);
    }

    /**
     * Controller to check the submission of new comment
     *
     * @param Application $app
     * @return string
     */
    public function controllerSubmit(Application $app)
    {
        $this->initParameters($app);

        $form = $this->getCommentForm();

        $form->bind($this->app['request']);

        if ((false !== ($recaptcha_check = $app['recaptcha']->isValid())) && $form->isValid()) {
            // get the submit
            self::$submit = $form->getData();
            if (empty(self::$submit['comment_content']) ||
                strlen(self::$submit['comment_content']) < self::$configuration['comments']['length']['minimum']) {
                // empty comment or comment too short
                $this->setMessage('Ooops, you have forgotten to post a comment or your comment is too short (minimum: %length% characters).',
                    array('%length%', self::$configuration['comments']['length']['minimum']));
                $this->promptForm($form);
            }
            if (strlen(self::$submit['comment_content']) > self::$configuration['comments']['length']['maximum']) {
                // comment exceed the maximum length
                $this->setMessage('Ooops, your comment exceeds the maximum length of %length% chars, please shorten it.',
                    array('%length%' => self::$configuration['comments']['length']['maximum']));
                $this->promptForm($form);
            }
            // comment is valid
            if (false !== (self::$contact_id = $this->ContactControl->existsLogin(self::$submit['contact_email']))) {
                // contact already exists, get the contact data
                self::$contact = $this->ContactControl->select(self::$contact_id);
            }
            else {
                // create a new contact
                $person = array(
                    'contact' => array(
                        'contact_id' => -1,
                        'contact_type' => 'PERSON',
                        'contact_name' => strtolower(self::$submit['contact_email']),
                        'contact_login' => strtolower(self::$submit['contact_email']),
                        'contact_status' => self::$configuration['contact']['confirmation']['double_opt_in'] ? 'PENDING' : 'ACTIVE',
                        'contact_since' => date('Y-m-d H:i:s')
                    ),
                    'person' => array(
                        array(
                            'person_id' => -1,
                            'person_nick_name' => self::$submit['contact_nick_name']
                        )
                    ),
                    'communication' => array(
                        array(
                            'communication_id' => -1,
                            'communication_type' => 'EMAIL',
                            'communication_usage' => 'PRIVATE',
                            'communication_value' => strtolower(self::$submit['contact_email'])
                        )
                    ),
                    'tag' => array(
                        array(
                            'tag_id' => -1,
                            'tag_name' => 'COMMENTS'
                        )
                    )
                );
                self::$contact_id = -1;
                if (!$this->ContactControl->insert($person, self::$contact_id)) {
                    // something went wrong, return with a message
                    $this->setMessage($this->ContactControl->getMessage());
                    return $this->promptForm($form);
                }
                self::$contact = $this->ContactControl->select(self::$contact_id);
            }

            if (self::$configuration['contact']['confirmation']['double_opt_in'] &&
                self::$contact['contact']['contact_status'] == 'PENDING') {
                // the contact must be confirmed before the comment can be published
                return $this->contactConfirmContact($form);
            }

            if (self::$contact['contact']['contact_status'] != 'ACTIVE') {
                // contact exists but has no ACTIVE status
                $this->setMessage('For the email address %email% exists a contact record, but the status does not allow you to post a comment. Please contact the <a href="mailto:%admin_email%">administrator</a>.',
                    array('%email%' => self::$submit['contact_email'], '%admin_email%' => self::$configuration['administrator']['email']), true);
                return $this->promptForm($form);
            }

            // set the tag COMMENTS if not exists
            $this->ContactControl->setContactTag('COMMENTS', self::$contact_id);

            if ((self::$contact['contact']['contact_type'] == 'PERSON') &&
                (empty(self::$contact['person'][0]['contact_nick_name']) ||
                    (self::$contact['person'][0]['person_nick_name'] != self::$submit['contact_nick_name']))) {
                // add or update the nickname to the contact
                self::$contact['person'][0]['person_nick_name'] = self::$submit['contact_nick_name'];
                $this->ContactControl->update(self::$contact, self::$contact_id);
            }

            print_r(self::$contact);
            print_r(self::$submit);
        }
        else {
            // the form check failed
            if (!$recaptcha_check) {
                // ReCaptcha error
                $this->setMessage($app['recaptcha']->getLastError());
            }
            else {
                // invalid form submission
                $this->setMessage('The form is not valid, please check your input and try again!');
            }
        }

        return $this->promptForm($form);
    }

    /**
     * Send a mail to the administrator to confirm the comment
     */
    protected function adminConfirmComment()
    {
        $body = $this->app['twig']->render($this->app['utils']->getTemplateFile(
            '@phpManufaktur/CommandCollection/Template/Comments',
            'mail/admin/confirm.comment.twig',
            $this->getPreferredTemplateStyle()),
            array(
                'contact' => self::$contact,
                'comment' => self::$comment,
                'link_publish_comment' => FRAMEWORK_URL.'/collection/comments/admin/confirm/'.self::$comment['comment_guid_2'],
                'link_reject_comment' => FRAMEWORK_URL.'/collection/comments/admin/reject/'.self::$comment['comment_guid_2'],
                'link_lock_contact' => FRAMEWORK_URL.'/collection/comments/admin/lock/'.self::$comment['comment_guid_2']
            ));

        // send a email to the contact
        $message = \Swift_Message::newInstance()
        ->setSubject(self::$comment['comment_headline'])
        ->setFrom(array(self::$configuration['administrator']['email']))
        ->setTo(array(self::$configuration['administrator']['email']))
        ->setBody($body)
        ->setContentType('text/html');
        // send the message
        $this->app['mailer']->send($message);
    }

    /**
     * Check the activation key for the email address
     *
     * @param Application $app
     * @param string $guid
     * @throws \Exception
     */
    public function controllerConfirmContact(Application $app, $guid)
    {
        $this->initParameters($app);

        // this controller is executed outside of the CMS and get no language info!
        $this->app['translator']->setLocale($app['request']->getPreferredLanguage());

        if (false === (self::$comment = $this->CommentsData->selectGUID($guid))) {
            throw new \Exception("Invalid call, the GUID $guid does not exists.");
        }
        self::$comment_id = self::$comment['comment_id'];

        if (self::$comment['comment_status'] == 'CONFIRMED') {
            // the comment is already confirmed
            $message = $this->app['translator']->trans('Your comment "%headline% is already published, the activation link is no longer valid',
                array('%headline%' => self::$comment['comment_headline']));
            return $this->app->redirect(self::$comment['comment_url'].'?message='.base64_encode($message));
        }

        // select the contact
        self::$contact_id = self::$comment['contact_id'];
        self::$contact = $this->ContactControl->select(self::$contact_id);

        if ((self::$contact['contact']['contact_status'] != 'PENDING') && (self::$contact['contact']['contact_status'] != 'ACTIVE')) {
            // unclear status
            $message = $this->app['translator']->trans('Your contact status is not as expected PENDING - please contact the <a href="mailto:%admin_email%">administrator</a>.');
            return $this->app->redirect(self::$comment['comment_url'].'?message='.base64_encode($message));
        }

        // update the contact status to ACTIVE
        self::$contact['contact']['contact_status'] = 'ACTIVE';
        $this->ContactControl->update(self::$contact, self::$contact_id);

        $identifier = $this->CommentsIdentifier->select(self::$comment['identifier_id']);
        if (($identifier['identifier_publish'] == 'CONFIRM_ADMIN') || ($identifier['identifier_publish'] == 'CONFIRM_EMAIL_ADMIN')) {
            // the comment must be also confirmed by the administrator
            $this->adminConfirmComment();
            // inform the contact
            $this->contactPendingConfirmation();
            // show a message
            $message = $this->app['translator']->trans('Thank you for the activation of your email address. You comment will be confirmed by the administrator and published as soon as possible.');
            return $this->app->redirect(self::$comment['comment_url'].'?message='.base64_encode($message));
        }

        // all done - publish the comment
        self::$comment['comment_confirmation'] = date('Y-m-d H:i:s');
        self::$comment['comment_status'] = 'CONFIRMED';
        $this->CommentsData->update(self::$comment, self::$comment_id);

        $message = $this->app['translator']->trans('Thank you for submitting the comment %headline%!',
            array('%headline%' => self::$comment['comment_headline']));
        return $this->app->redirect(self::$comment['comment_url'].'?message='.base64_encode($message));
    }

    /**
     * Controller to confirm the publishing of a comment
     *
     * @param Application $app
     * @param string $guid
     * @throws \Exception
     */
    public function controllerConfirmComment(Application $app, $guid)
    {
        $this->initParameters($app);

        // this controller is executed outside of the CMS and get no language info!
        $this->app['translator']->setLocale($app['request']->getPreferredLanguage());

        if (false === (self::$comment = $this->CommentsData->selectAdminGUID($guid))) {
            throw new \Exception("Invalid call, the GUID $guid does not exists.");
        }
        self::$comment_id = self::$comment['comment_id'];

        if (self::$comment['comment_status'] == 'CONFIRMED') {
            // the comment is already confirmed
            $message = $this->app['translator']->trans('The comment with the ID %id% is already published!',
                array('%id%' => self::$comment['comment_id']));
            return $this->app->redirect(self::$comment['comment_url'].'?message='.base64_encode($message));
        }

        if (self::$comment['comment_status'] == 'REJECTED') {
            $message = $this->app['translator']->trans('The comment with the ID %id% is already marked as REJECTED!',
                array('%id%' => self::$comment['comment_id']));
            return $this->app->redirect(self::$comment['comment_url'].'?message='.base64_encode($message));
        }

        // publish the comment
        self::$comment['comment_confirmation'] = date('Y-m-d H:i:s');
        self::$comment['comment_status'] = 'CONFIRMED';
        $this->CommentsData->update(self::$comment, self::$comment_id);

        // send a information to the contact
        $this->contactPublishedComment();

        $message = $this->app['translator']->trans('The comment with the ID %id% has confirmed and published.',
            array('%id%' => self::$comment['comment_id']));
        return $this->app->redirect(self::$comment['comment_url'].'?message='.base64_encode($message));
    }

    /**
     * Controller to REJECT a comment
     *
     * @param Application $app
     * @param string $guid
     * @throws \Exception
     */
    public function controllerRejectComment(Application $app, $guid)
    {
        $this->initParameters($app);

        // this controller is executed outside of the CMS and get no language info!
        $this->app['translator']->setLocale($app['request']->getPreferredLanguage());

        if (false === (self::$comment = $this->CommentsData->selectAdminGUID($guid))) {
            throw new \Exception("Invalid call, the GUID $guid does not exists.");
        }
        self::$comment_id = self::$comment['comment_id'];

        if (self::$comment['comment_status'] == 'REJECTED') {
            // the comment is already confirmed
            $message = $this->app['translator']->trans('The comment with the ID %id% is already marked as REJECTED!',
                array('%id%' => self::$comment['comment_id']));
            return $this->app->redirect(self::$comment['comment_url'].'?message='.base64_encode($message));
        }

        // REJECT the comment
        self::$comment['comment_confirmation'] = date('Y-m-d H:i:s');
        self::$comment['comment_status'] = 'REJECTED';
        $this->CommentsData->update(self::$comment, self::$comment_id);

        // send a information to the contact
        $this->contactRejectComment();

        $message = $this->app['translator']->trans('The comment with the ID %id% is REJECTED.',
            array('%id%' => self::$comment['comment_id']));
        return $this->app->redirect(self::$comment['comment_url'].'?message='.base64_encode($message));
    }

    /**
     * Controller to REJECT a comment and to LOCK the contact which tried to
     * publish this comment.
     *
     * @param Application $app
     * @param string $guid
     * @throws \Exception
     */
    public function controllerLockContact(Application $app, $guid)
    {
        $this->initParameters($app);

        // this controller is executed outside of the CMS and get no language info!
        $this->app['translator']->setLocale($app['request']->getPreferredLanguage());

        if (false === (self::$comment = $this->CommentsData->selectAdminGUID($guid))) {
            throw new \Exception("Invalid call, the GUID $guid does not exists.");
        }
        self::$comment_id = self::$comment['comment_id'];

        // REJECT the comment
        self::$comment['comment_confirmation'] = date('Y-m-d H:i:s');
        self::$comment['comment_status'] = 'REJECTED';
        $this->CommentsData->update(self::$comment, self::$comment_id);

        // ... and LOCK the contact!
        self::$contact = $this->ContactControl->select(self::$comment['contact_id']);
        self::$contact_id = self::$contact['contact']['contact_id'];

        self::$contact['contact']['contact_status'] = 'LOCKED';
        $this->ContactControl->update(self::$contact, self::$contact_id);
        $this->ContactControl->addProtocolInfo(self::$contact_id,
            "The contact is LOCKED because the comment with the ID ".self::$comment['comment_id']." was REJECTED.");

        // send a information to the contact
        $this->contactLockContact();

        $message = $this->app['translator']->trans('The comment with the ID %comment_id% is REJECTED and too, the contact with the ID %contact_id% has LOCKED.',
            array('%comment_id%' => self::$comment['comment_id'], '%contact_id%' => self::$contact_id));
        return $this->app->redirect(self::$comment['comment_url'].'?message='.base64_encode($message));
    }
}
