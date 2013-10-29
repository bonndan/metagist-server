<?php

namespace Metagist\ServerBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
// these import the "@Route" and "@Template" annotations
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Doctrine\Common\Collections\Collection;
use Pagerfanta\Adapter\DoctrineCollectionAdapter;
use Pagerfanta\Pagerfanta;
use Metagist\ServerBundle\TWBS\TwitterBootstrapView;
use Metagist\ServerBundle\Entity\Rating;
use Metagist\ServerBundle\Entity\Metainfo;
use Metagist\ServerBundle\Entity\Package;

/**
 * Web controller
 * 
 * @Route("/", service="metagist.web.controller")
 */
class WebController extends Controller
{

    /**
     * service provider
     * 
     * @var \Metagist\ServerBundle\Controller\ServiceProvider
     */
    private $serviceProvider;

    /**
     * Constructor
     * 
     * @param \Metagist\ServerBundle\Controller\ServiceProvider $serviceProvider
     */
    public function __construct(ServiceProvider $serviceProvider)
    {
        $this->serviceProvider = $serviceProvider;
    }

    /**
     * Routing setup.
     * 
     * 
     */
    protected function initRoutes()
    {
        $routes = array(
            'errors' => array('match' => '/errors', 'method' => 'errors'),
            'loginNotice' => array('match' => '/login', 'method' => 'loginNotice'),
            'logout' => array('match' => '/auth/logout', 'method' => 'logout'),
            'ratings-pp' => array('match' => '/ratings/{author}/{name}/{page}', 'method' => 'ratings'),
            'contribute-list' => array('match' => '/contribute/list/{author}/{name}', 'method' => 'contributeList'),
            'contribute' => array('match' => '/contribute/{author}/{name}/{group}', 'method' => 'contribute'),
            'search' => array('match' => '/search', 'method' => 'search'),
            'search-page' => array('match' => '/search/{query}/{page}', 'method' => 'search'),
            'update' => array('match' => '/update/{author}/{name}', 'method' => 'update'),
            'latest' => array('match' => '/latest', 'method' => 'latest'),
        );

        foreach ($routes as $name => $data) {
            $this->serviceProvider
                ->match($data['match'], array($this, $data['method']))
                ->bind($name);
        }

        $this->registerErrorFunction();
    }

    /**
     * Default.
     * 
     * @return string
     * @Route("/", name="homepage")
     * @Template()
     */
    public function indexAction()
    {
        return array(
            'packages' => $this->serviceProvider->packages()->random(20),
            'categories' => $this->getCategories()
        );
    }
    
    /**
     * Features packages.
     * 
     * @return string
     * @Route("/featured", name="featured")
     * @Template()
     */
    public function featuredAction()
    {
        $repo = $this->serviceProvider->metainfo();
        return array(
            'featured' => $repo->byGroup('featured'),
            'categories' => $this->getCategories()
        );
    }
    
    

    /**
     * Show the latest updates and ratings.
     * 
     * @return string
     * @Route("/latest", name="latest")
     * @Template()
     */
    public function latestAction()
    {
        $repo = $this->serviceProvider->metainfo();
        $ratings = $this->serviceProvider->ratings();
        return array(
            'latestUpdates' => $repo->latest(),
            'latestRatings' => $ratings->latest(5),
        );
    }

    /**
     * Show the about info
     * 
     * @return string
     * @Route("/about", name="about")
     * @Template()
     */
    public function aboutAction()
    {
        return array();
    }

    /**
     * Show the user profile
     * 
     * @return string
     * @Route("/me", name="profile")
     * @Template()
     */
    public function profileAction()
    {
        return array(
            'user' => $this->getUser(),
            'ratings' => $this->serviceProvider->ratings()->byUser($this->getUser())
        );
    }

    /**
     * Shows package info.
     * 
     * @param string $author
     * @param string $name
     * @return string
     * @Route("/package/{author}/{name}", name="package")
     * @Template()
     */
    public function packageAction($author, $name)
    {
        $package = $this->serviceProvider->getPackage($author, $name);

        return array(
            'package' => $package,
            'categories' => $this->serviceProvider->categories(),
            'ratings' => $this->serviceProvider->ratings()->byPackage($package, 0, 3),
            'consumers' => $this->serviceProvider->dependencies()->getConsumersOf($package)
        );
    }

    /**
     * Updates package info by invoking the worker.
     * 
     * @param string $author
     * @param string $name
     * @return string
     */
    public function update($author, $name)
    {
        $flashBag = $this->serviceProvider->session()->getFlashBag();
        try {
            $package = $this->getPackage($author, $name);
            $this->serviceProvider->getApi()->worker()->scan($author, $name);
        } catch (\Exception $exception) {
            $flashBag->add(
                'error', 'Error while updating the package: ' . $exception->getMessage()
            );
            $this->serviceProvider->logger()->error('Exception: ' . $exception->getMessage());
            return $this->serviceProvider->redirect('/');
        }

        $flashBag->add(
            'success', 'The package ' . $package->getIdentifier() . ' will be updated. Thanks.'
        );
        return $this->redirectToPackageView($package);
    }

    /**
     * Shows a users profile
     * 
     * @param string $name
     * @return string
     * @Route("/user/{name}", name="user")
     * @Template()
     */
    public function userAction($name)
    {
        $repo = $this->getDoctrine()->getEntityManager()->getRepository('MetagistServerBundle:User');
        $user = $repo->findOneBy(array('username' => $name));

        if (!$user) {
            return $this->redirect('/');
        }

        return array(
            'user' => $user,
            'ratings' => $this->serviceProvider->ratings()->byUser($user)
        );
    }

    /**
     * Shows the package ratings.
     * 
     * @param sting  $author
     * @param string $name
     * @return string
     * @Route("/ratings/{author}/{name}", name="ratings")
     * @Template()
     */
    public function ratingsAction($author, $name, $page = 1)
    {
        $package = $this->serviceProvider->packages()->byAuthorAndName($author, $name);
        $ratings = $this->serviceProvider->ratings()->byPackage($package);
        $routeGen = function($page) {
                return '/ratings/' . $page;
            };
        $pager = $this->getPaginationFor($ratings);
        $pager->setCurrentPage($page);
        $view = new TwitterBootstrapView();

        return array(
            'package' => $package,
            'ratings' => $pager,
            'pagination' => $view->render($pager, $routeGen)
        );
    }

    /**
     * Rate a package.
     * 
     * @param string $author
     * @param string $name
     * @return string
     * @Route("/contribute/rate/{author}/{name}", name="rate")
     * @Template()
     */
    public function rateAction($author, $name)
    {
        $request = $this->getRequest();
        $package = $this->serviceProvider->packages()->byAuthorAndName($author, $name);
        $flashBag = $this->serviceProvider->session()->getFlashBag();
        $user = $this->getUser();
        $rating = $this->serviceProvider->ratings()->byPackageAndUser($package, $user);
        $form = $this->getFormFactory()->getRateForm($package->getVersions(), $rating);

        if ($request->isMethod('POST')) {
            $form->bind($request);
            if ($form->isValid()) {
                $data = $form->getData();
                if ($rating === null) {
                    $data['package'] = $package;
                    $rating = Rating::fromArray($data);
                }
                $rating->setUser($user);
                $this->serviceProvider->ratings()->save($rating);
                $average = $this->serviceProvider->ratings()->getAverageForPackage($package);
                $package->setOverallRating($average);
                $this->serviceProvider->packages()->save($package);

                $flashBag->add('success', 'Thanks.');
                return $this->redirectToPackageView($package);
            } else {
                $form->addError(new FormError('Please check the entered value.'));
            }
        }

        return array(
            'package' => $package,
            'form' => $form->createView()
        );
    }

    /**
     * Lists the categories and groups to contribute to.
     * 
     * @param string $author
     * @param string $name
     * @return string
     * @Route("/contribute-list/{author}/{name}", name="contribute-list")
     * @Template()
     */
    public function contributeListAction($author, $name)
    {
        $package = $this->serviceProvider->packages()->byAuthorAndName($author, $name);
        
        return array(
            'package' => $package,
            'categories' => $this->serviceProvider->categories()
        );
    }
    
    /**
     * Contribute to the package (provide information).
     * 
     * @param string  $author
     * @param string  $name
     * @param string  $group
     * @return string
     * @Route("/contribute/{author}/{name}/{group}", name="contribute")
     * @Template()
     */
    public function contributeAction($author, $name, $group)
    {
        $request = $this->getRequest();
        $package = $this->serviceProvider->packages()->byAuthorAndName($author, $name);
        $flashBag = $this->serviceProvider->session()->getFlashBag();
        $category = $this->serviceProvider->categories()->getCategoryForGroup($group);
        $groups = $this->serviceProvider->categories()->getGroups($category);
        $groupData = $groups[$group];
        $form = $this->getFormFactory()->getContributeForm(
            $package->getVersions(), $groupData->type
        );

        if ($request->isMethod('POST')) {
            $form->bind($request);
            if ($form->isValid()) {
                $data = $form->getData();
                $metaInfo = Metainfo::fromValue($group, $data['value'], $data['version']);
                $metaInfo->setPackage($package);
                $metaInfo->setUser($this->getUser());

                try {
                    $this->serviceProvider->metainfo()->save($metaInfo);
                    $flashBag->add('success', 'Info saved. Thank you.');
                } catch (Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
                    $this->serviceProvider->logger()->warn($exception->getMessage());
                    $flashBag->add('error', 'Access denied to ' . $group);
                }

                return $this->redirectToPackageView($package);
            } else {
                $form->addError(new FormError('Please check the entered value.'));
            }
        }

        return array(
            'package' => $package,
            'form' => $form->createView(),
            'category' => $category,
            'group' => $group,
            'type' => $groupData->type,
            'description' => $groupData->description,
        );
    }

    /**
     * Search for a package.
     * 
     * @param Request $request
     * @return string
     * @Route(
     *     "/search/{page}/{query}",
     *     defaults={"page" = 1, "query" = ""},
     *     requirements={"page" = "\d+"},
     *     name="search"
     * )
     * @Route("/search?query={query}")
     * @Template()
     * @link http://www.terrymatula.com/development/2013/some-packagist-api-hacks/
     */
    public function searchAction($page, $query, Request $request)
    {
        
        if (empty($query)) {
            $query = $request->query->get('query');
            if (empty($query)) {
                $flashBag = $this->serviceProvider->session()->getFlashBag();
                $flashBag->add('error', 'Please enter a search query.');
                return $this->redirect($this->generateUrl('homepage'));
            }
        }
        
        @list ($author, $name) = explode('/', $query);
        $package = null;
        try {
            $package = $this->serviceProvider->packages()->byAuthorAndName($author, $name);
            if ($package !== null) {
                return $this->redirectToPackageView($package);
            }
        } catch (\Exception $exception) {
            $this->serviceProvider->logger()->info('Search failed: ' . $exception->getMessage());
        }
        
        $api = new \Packagist\Api\Client();
        $response = $api->search($query, array('page' => $page));
        
        
        $packages = new \Doctrine\Common\Collections\ArrayCollection();
        foreach ($response as $result) {
            /* @var $result \Packagist\Api\Result\Result */
            $identifier = $result->getName();
            list ($author, $name) = Package::splitIdentifier($identifier);
            $package = $this->serviceProvider->packages()->byAuthorAndName($author, $name);
            if (!$package) {
                $package = new Package($identifier);
                $package->setDescription($result->getDescription());
            }
            $packages->add($package);
        }

        $that = $this;
        $routeGenerator = function($page) use ($that, $query) {
            return $that->generateUrl('search', array('query' => urlencode($query), 'page' => $page));
        };
        $pagerfanta = $this->getPaginationFor($packages);
        $pagerfanta->setCurrentPage($page);
        $view = new TwitterBootstrapView();

        return array(
            'query' => $query,
            'packages' => $pagerfanta,
            'pagination' => $view->render($pagerfanta, $routeGenerator)
        );
    }

    /**
     * Just displays the notice that the user has to be logged in.
     * 
     * @return array
     * @Route("/login", name="login")
     * @Template()
     */
    public function loginAction()
    {
        return array();
    }

    /**
     * Just displays the notice that the user has to be logged in.
     * 
     * @return array
     * @Route("/admin/style/{author}/{name}", name="style")
     * @Template()
     */
    public function uploadimageAction($author, $name, Request $request)
    {
        $flashBag = $this->serviceProvider->session()->getFlashBag();
        $package = $this->serviceProvider->packages()->byAuthorAndName($author, $name);
        $repo = $this->getDoctrine()->getRepository("\Metagist\ServerBundle\Entity\Image");
        $image = $repo->byPackage($package);
        $form = $this->createFormBuilder($image)
            ->add('file')
            ->add('style')
            ->getForm();

        $form->handleRequest($request);
        if ($request->isMethod('POST')) {
            if ($form->isValid()) {
                $em = $this->getDoctrine()->getManager();
                $em->persist($image);
                $em->flush();
                $flashBag->add('success', 'Image received.');

                return $this->redirectToPackageView($package);
            } else {
                $flashBag->add('error', 'Invalid file uploaded.');
            }
        }

        return array(
            'form' => $form->createView(),
            'package' => $package
        );
    }

    /**
     * 
     * @return void
     */
    protected function registerErrorFunction()
    {
        $app = $this->serviceProvider;
        $this->serviceProvider->error(function (\Exception $exception, $code) use ($app) {
                if ($app['debug']) {
                    return;
                }

                switch ($code) {
                    case 404:
                        $message = 'The requested page could not be found.';
                        break;
                    default:
                        $message = 'We are sorry, but something went terribly wrong.';
                }

                return new Response($message, $code);
            });
    }

    /**
     * Returns the form factory.
     * 
     * @return \Metagist\FormFactory
     */
    protected function getFormFactory()
    {
        return new \Metagist\ServerBundle\Form\FormFactory(
            $this->get('form.factory'), $this->serviceProvider->categories()
        );
    }

    /**
     * Creates a pagination for the given collection.
     * 
     * @param \Doctrine\Common\Collections\Collection $collection
     * @return Pagerfanta
     */
    protected function getPaginationFor(Collection $collection, $maxPerPage = 25)
    {
        $adapter = new DoctrineCollectionAdapter($collection);
        $pagerfanta = new Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage($maxPerPage);
        return $pagerfanta;
    }

    /**
     * Redirects to the package view.
     * 
     * @param \Metagist\ServerBundle\Entity\Package $package
     * @return RedirectResponse
     */
    protected function redirectToPackageView(\Metagist\ServerBundle\Entity\Package $package)
    {
        return $this->redirect(
                $this->generateUrl('package', array('author' => $package->getAuthor(), 'name' => $package->getName()))
        );
    }

    protected function getCategories()
    {
        return array(
            'featured' => $this->generateUrl('featured')
        );
    }
}
